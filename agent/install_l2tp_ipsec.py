#!/usr/bin/env python3
"""Add a virVPN-managed L2TP/IPsec control plane without altering legacy PPP users."""

import os
import secrets
import shutil
import subprocess
import sys
import time
from pathlib import Path

ROOT = Path(__file__).resolve().parent
CONFIG = Path("/etc/virvpn-l2tp")
STATE = Path("/var/lib/virvpn-l2tp")
CODE = Path("/opt/virvpn-l2tp")
IPSEC_CONF = Path("/etc/ipsec.d/virvpn-l2tp.conf")
IPSEC_SECRET = Path("/etc/ipsec.d/virvpn-l2tp.secrets")


def run(*arguments: str) -> str:
    completed = subprocess.run(arguments, capture_output=True, text=True, timeout=90)
    if completed.returncode:
        raise RuntimeError("Command failed: " + arguments[0])
    return completed.stdout


def write(path: Path, contents: str, mode: int) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(contents, encoding="utf-8")
    os.chmod(path, mode)


def ensure_include(path: Path, include: str) -> None:
    contents = path.read_text(encoding="utf-8") if path.exists() else ""
    if include not in contents:
        write(path, contents.rstrip() + "\n\n# virVPN managed include\n" + include + "\n", 0o600)


def main() -> None:
    if os.geteuid() != 0:
        raise SystemExit("Run this installer as root.")
    public_ip = os.getenv("VV_L2TP_PUBLIC_IP", "").strip()
    if not public_ip:
        raise SystemExit("Set VV_L2TP_PUBLIC_IP to the public IPv4 address before running.")
    run("/usr/bin/apt-get", "update", "-qq")
    run("/usr/bin/apt-get", "install", "-y", "-qq", "strongswan-starter", "strongswan-plugin-openssl", "iptables")
    for directory in (CONFIG, STATE, CODE):
        directory.mkdir(parents=True, exist_ok=True)
        os.chmod(directory, 0o700)
    environment = CONFIG / "agent.env"
    values = {}
    if environment.exists():
        values = dict(line.split("=", 1) for line in environment.read_text(encoding="utf-8").splitlines() if "=" in line)
    values.setdefault("VPN_AGENT_SHARED_SECRET", secrets.token_hex(32))
    values.setdefault("VV_L2TP_IPSEC_PSK", secrets.token_urlsafe(32))
    values.update({"VPN_AGENT_PORT": "9447", "VV_L2TP_PPP_SERVER": "LinuxVPN", "VV_L2TP_PUBLIC_IP": public_ip})
    write(environment, "".join(key + "=" + value + "\n" for key, value in values.items()), 0o600)
    ensure_include(Path("/etc/ipsec.conf"), "include /etc/ipsec.d/*.conf")
    ensure_include(Path("/etc/ipsec.secrets"), "include /etc/ipsec.d/*.secrets")
    write(IPSEC_CONF, """# Managed by virVPN. Existing L2TP/PPP services are not changed.
conn virvpn-l2tp
    keyexchange=ikev1
    type=transport
    authby=psk
    left=%any
    leftprotoport=17/1701
    right=%any
    rightprotoport=17/%any
    ike=aes256-sha256-modp2048,aes128-sha1-modp1024
    esp=aes256-sha256,aes128-sha1
    dpddelay=30s
    dpdtimeout=120s
    dpdaction=clear
    auto=add
""", 0o600)
    write(IPSEC_SECRET, ': PSK "' + values["VV_L2TP_IPSEC_PSK"] + '"\n', 0o600)
    for source in ("l2tp_agent.py", "vpn_agent.py"):
        shutil.copyfile(ROOT / source, CODE / source)
        os.chmod(CODE / source, 0o700)
    shutil.copyfile(ROOT / "virvpn-l2tp-agent.service", Path("/etc/systemd/system/virvpn-l2tp-agent.service"))
    # These rules are narrowly scoped to the new IPsec handshake ports; the
    # legacy UDP/1701 listener is deliberately not changed.
    for port in ("500", "4500"):
        rule = ["-p", "udp", "--dport", port, "-m", "comment", "--comment", "virvpn-l2tp-ipsec", "-j", "ACCEPT"]
        if subprocess.run(["/usr/sbin/iptables", "-C", "INPUT", *rule], capture_output=True, text=True).returncode:
            run("/usr/sbin/iptables", "-I", "INPUT", "1", *rule)
    run("/usr/bin/systemctl", "daemon-reload")
    run("/usr/bin/systemctl", "enable", "--now", "strongswan-starter.service", "virvpn-l2tp-agent.service")
    for _ in range(10):
        if subprocess.run(["/usr/bin/systemctl", "is-active", "--quiet", "strongswan-starter.service"]).returncode == 0 and subprocess.run(["/usr/bin/systemctl", "is-active", "--quiet", "virvpn-l2tp-agent.service"]).returncode == 0:
            print("virVPN L2TP/IPsec installed: UDP 1701, IPsec UDP 500/4500, agent 127.0.0.1:9447")
            return
        time.sleep(1)
    raise RuntimeError("L2TP/IPsec agent did not become ready; inspect systemd logs.")


if __name__ == "__main__":
    main()
