#!/usr/bin/env python3
"""Install an isolated virVPN WireGuard server and its loopback-only agent.

This installer deliberately uses a new interface, UDP port and subnet. It does
not inspect or alter legacy OpenVPN, L2TP, Docker, or existing WireGuard state.
"""
from __future__ import annotations

import os
import secrets
import shutil
import stat
import subprocess
import sys
from pathlib import Path


ROOT = Path("/")
SOURCE = Path(__file__).resolve().parent
WG_DIR = ROOT / "etc/wireguard"
AGENT_DIR = ROOT / "etc/vpnops-agent"
STATE_DIR = ROOT / "var/lib/vpnops-agent"
SERVICE_DIR = ROOT / "etc/systemd/system"
FIREWALL_SCRIPT = ROOT / "usr/local/sbin/virvpn-wireguard-firewall"
FIREWALL_UNIT = SERVICE_DIR / "virvpn-wireguard-firewall.service"
WG_SERVICE = "wg-quick@wg-virvpn.service"
AGENT_SERVICE = "vpn-agent.service"

INTERFACE = "wg-virvpn"
ADDRESS = "10.66.0.1/24"
NETWORK = "10.66.0.0/24"
PORT = "51820"
WAN_INTERFACE = os.getenv("VV_WG_WAN_INTERFACE", "eth0")


def run(*arguments: str) -> str:
    completed = subprocess.run(arguments, check=True, text=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    return completed.stdout.strip()


def write(path: Path, contents: str, mode: int = 0o600) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(contents, encoding="utf-8")
    path.chmod(mode)


def require_root() -> None:
    if os.geteuid() != 0:
        raise SystemExit("Run this installer as root.")


def package_install() -> None:
    run("apt-get", "update", "-qq")
    run("apt-get", "install", "-y", "-qq", "wireguard-tools", "iptables", "openssl")


def ensure_key(path: Path) -> str:
    if not path.exists():
        key = run("/usr/bin/wg", "genkey")
        write(path, key + "\n")
    return path.read_text(encoding="utf-8").strip()


def install_wireguard() -> None:
    WG_DIR.mkdir(parents=True, exist_ok=True)
    WG_DIR.chmod(0o700)
    private_key = ensure_key(WG_DIR / "virvpn-server.key")
    process = subprocess.run(["/usr/bin/wg", "pubkey"], input=private_key + "\n", check=True, text=True, stdout=subprocess.PIPE)
    public_key = process.stdout.strip()
    config = WG_DIR / f"{INTERFACE}.conf"
    if not config.exists():
        write(config, f"[Interface]\nAddress = {ADDRESS}\nListenPort = {PORT}\nPrivateKey = {private_key}\nMTU = 1420\nSaveConfig = false\n")
    public_file = ROOT / "etc/virvpn-wireguard/server-public.env"
    write(public_file, f"VV_WG_PUBLIC_KEY={public_key}\nVV_WG_ENDPOINT_PORT={PORT}\nVV_WG_NETWORK={NETWORK}\n", 0o644)
    write(ROOT / "etc/sysctl.d/99-virvpn-wireguard.conf", "net.ipv4.ip_forward=1\n", 0o644)
    run("sysctl", "--system")


def install_firewall() -> None:
    write(FIREWALL_SCRIPT, f'''#!/bin/sh
set -eu
ACTION="${{1:-apply}}"
chain_rule() {{
  table="$1"; shift
  if [ "$ACTION" = remove ]; then
    iptables $table -D "$@" 2>/dev/null || true
  elif ! iptables $table -C "$@" 2>/dev/null; then
    iptables $table -A "$@"
  fi
}}
chain_rule "" FORWARD -i {INTERFACE} -o {WAN_INTERFACE} -s {NETWORK} -j ACCEPT
chain_rule "" FORWARD -i {WAN_INTERFACE} -o {INTERFACE} -d {NETWORK} -m conntrack --ctstate ESTABLISHED,RELATED -j ACCEPT
chain_rule "-t nat" POSTROUTING -s {NETWORK} -o {WAN_INTERFACE} -j MASQUERADE
''', 0o700)
    write(FIREWALL_UNIT, f'''[Unit]
Description=virVPN WireGuard forwarding and NAT rules
After=network-online.target {WG_SERVICE}
Wants=network-online.target
Requires={WG_SERVICE}

[Service]
Type=oneshot
RemainAfterExit=yes
ExecStart={FIREWALL_SCRIPT} apply
ExecStop={FIREWALL_SCRIPT} remove

[Install]
WantedBy=multi-user.target
''', 0o644)


def install_agent() -> None:
    AGENT_DIR.mkdir(parents=True, exist_ok=True)
    AGENT_DIR.chmod(0o700)
    STATE_DIR.mkdir(parents=True, exist_ok=True)
    STATE_DIR.chmod(0o700)
    agent_source = SOURCE / "vpn_agent.py"
    unit_source = SOURCE / "vpn-agent.service"
    if not agent_source.exists() or not unit_source.exists():
        raise SystemExit("vpn_agent.py and vpn-agent.service must be beside this installer.")
    destination = ROOT / "opt/vpnops-agent/vpn_agent.py"
    destination.parent.mkdir(parents=True, exist_ok=True)
    shutil.copy2(agent_source, destination)
    destination.chmod(0o700)
    certificate = AGENT_DIR / "tls.crt"
    key = AGENT_DIR / "tls.key"
    if not certificate.exists() or not key.exists():
        run("openssl", "req", "-x509", "-newkey", "rsa:3072", "-nodes", "-days", "3650", "-subj", "/CN=virvpn-wireguard-agent", "-keyout", str(key), "-out", str(certificate))
        key.chmod(0o600)
        certificate.chmod(0o644)
    environment = AGENT_DIR / "agent.env"
    old = environment.read_text(encoding="utf-8") if environment.exists() else ""
    secret = next((line.split("=", 1)[1] for line in old.splitlines() if line.startswith("VPN_AGENT_SHARED_SECRET=")), secrets.token_urlsafe(48))
    write(environment, "\n".join([
        "VPN_AGENT_BIND=127.0.0.1", "VPN_AGENT_PORT=9443", f"VPN_AGENT_SHARED_SECRET={secret}",
        f"VPN_AGENT_TLS_CERT={certificate}", f"VPN_AGENT_TLS_KEY={key}",
        f"VPN_AGENT_WG_INTERFACE={INTERFACE}", f"VPN_AGENT_WG_ALLOWED_CIDR={NETWORK}", f"VPN_AGENT_WG_SERVER_PUBLIC_KEY={public_key}", "VPN_AGENT_WG_PERSIST=1", "",
    ]))
    shutil.copy2(unit_source, SERVICE_DIR / AGENT_SERVICE)
    (SERVICE_DIR / AGENT_SERVICE).chmod(0o644)


def main() -> None:
    require_root()
    if not WAN_INTERFACE.replace("-", "").replace("_", "").isalnum():
        raise SystemExit("VV_WG_WAN_INTERFACE is invalid")
    package_install()
    install_wireguard()
    install_firewall()
    install_agent()
    run("systemctl", "daemon-reload")
    run("systemctl", "enable", "--now", WG_SERVICE, "virvpn-wireguard-firewall.service", AGENT_SERVICE)
    print("virVPN WireGuard installed: endpoint UDP 51820, network 10.66.0.0/24, agent loopback 127.0.0.1:9443")


if __name__ == "__main__":
    main()
