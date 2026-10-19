#!/usr/bin/env python3
"""Local OpenVPN account agent. No request can select a command or filesystem path."""

import contextlib
import hashlib
import hmac
import ipaddress
import json
import os
import re
import secrets
import shlex
import socket
import subprocess
import sys
import tempfile
import threading
import uuid
from dataclasses import dataclass
from http import HTTPStatus
from http.server import ThreadingHTTPServer
from pathlib import Path

try:
    import fcntl
except ImportError:  # Unit tests can run on Windows; production main requires Linux.
    fcntl = None

from vpn_agent import AgentHandler, SHARED_SECRET, metrics


@dataclass
class Config:
    state: Path = Path(os.getenv("VV_OVPN_STATE", "/var/lib/virvpn-openvpn/accounts.json"))
    ccd: Path = Path(os.getenv("VV_OVPN_CCD", "/etc/virvpn-openvpn/ccd"))
    management: str = os.getenv("VV_OVPN_SOCKET", "/run/virvpn-openvpn/management.sock")
    ca: Path = Path(os.getenv("VV_OVPN_CA", "/etc/virvpn-openvpn/ca.crt"))
    network: str = os.getenv("VV_OVPN_NETWORK", "10.77.0.0/24")
    gateway: str = os.getenv("VV_OVPN_GATEWAY", "10.77.0.1")
    pool_start: str = os.getenv("VV_OVPN_POOL_START", "10.77.0.10")
    pool_end: str = os.getenv("VV_OVPN_POOL_END", "10.77.0.254")
    wan: str = os.getenv("VV_OVPN_WAN", "eth0")
    tun: str = os.getenv("VV_OVPN_TUN", "tun-virvpn")
    public_port: int = int(os.getenv("VV_OVPN_PUBLIC_PORT", "1196"))
    cert_name: str = os.getenv("VV_OVPN_CERT_NAME", "vpn2.viruzs.my.id")


def atomic_write(path: Path, content: str, mode: int = 0o600) -> None:
    with tempfile.NamedTemporaryFile(mode="w", encoding="utf-8", dir=path.parent, delete=False) as handle:
        temporary = Path(handle.name)
        try:
            os.chmod(temporary, mode)
            handle.write(content)
            handle.flush()
            os.fsync(handle.fileno())
            handle.close()
            os.replace(temporary, path)
        finally:
            temporary.unlink(missing_ok=True)


def password_matches(record: dict, password: str) -> bool:
    digest = hashlib.pbkdf2_hmac("sha256", password.encode(), bytes.fromhex(record["salt"]), 310_000).hex()
    return hmac.compare_digest(digest, record["password_hash"])


class State:
    def __init__(self, config: Config):
        self.config = config
        self.thread_lock = threading.RLock()

    @contextlib.contextmanager
    def locked(self):
        with self.thread_lock, self.config.state.with_suffix(".lock").open("a+") as lock:
            if fcntl:
                fcntl.flock(lock, fcntl.LOCK_EX)
            try:
                yield
            finally:
                if fcntl:
                    fcntl.flock(lock, fcntl.LOCK_UN)

    def read(self) -> dict:
        if not self.config.state.exists():
            return {"version": 1, "accounts": {}}
        state = json.loads(self.config.state.read_text(encoding="utf-8"))
        if state.get("version") != 1 or not isinstance(state.get("accounts"), dict):
            raise RuntimeError("Account state is invalid")
        return state

    def save(self, state: dict) -> None:
        atomic_write(self.config.state, json.dumps(state, separators=(",", ":")))


class Management:
    def __init__(self, config: Config):
        self.config = config

    def command(self, command: str, status: bool = False) -> str:
        with socket.socket(socket.AF_UNIX, socket.SOCK_STREAM) as connection:
            connection.settimeout(4)
            connection.connect(self.config.management)
            connection.sendall((command + "\n").encode())
            output = bytearray()
            while len(output) < 1_048_576:
                chunk = connection.recv(16_384)
                if not chunk:
                    break
                output.extend(chunk)
                text = output.decode("utf-8", errors="replace")
                if (status and "\nEND" in text) or (not status and ("SUCCESS:" in text or "ERROR:" in text)):
                    if "ERROR:" in text:
                        raise RuntimeError("OpenVPN management command failed")
                    return text
            raise RuntimeError("OpenVPN management response is incomplete")

    def sessions(self) -> list[dict]:
        headers = []
        sessions = []
        for line in self.command("status 3", status=True).splitlines():
            columns = line.split("\t")
            if columns[:2] == ["HEADER", "CLIENT_LIST"]:
                headers = columns[2:]
            elif columns[0] == "CLIENT_LIST" and headers:
                row = dict(zip(headers, columns[1:]))
                client_id = row.get("Client ID", "")
                if client_id.isdigit():
                    sessions.append({
                        "id": "ovpn:" + client_id, "identifier": row.get("Username") or row.get("Common Name"),
                        "service": "ovpn", "address": row.get("Virtual Address"),
                        "caller_id": row.get("Real Address"), "bytes_in": int(row.get("Bytes Received", 0)),
                        "bytes_out": int(row.get("Bytes Sent", 0)), "connected_at": row.get("Connected Since"),
                    })
        return sessions

    def disconnect(self, identifier: str, session_id: str | None = None) -> None:
        for session in self.sessions():
            if session["identifier"] == identifier and (session_id is None or session["id"] == session_id):
                self.command("client-kill " + session["id"].split(":")[1])


class Firewall:
    DNAT, SNAT, FWD = "VIRVPN_OVPN_DNAT", "VIRVPN_OVPN_SNAT", "VIRVPN_OVPN_FWD"

    def __init__(self, config: Config):
        self.config = config

    def run(self, arguments: list[str], check: bool = True) -> subprocess.CompletedProcess:
        result = subprocess.run(["/usr/sbin/iptables", "-w", "5", *arguments], capture_output=True, text=True, timeout=10)
        if check and result.returncode:
            raise RuntimeError("Managed firewall operation failed")
        return result

    def ensure(self, table: str, chain: str, rule: list[str], position: bool = False) -> None:
        if self.run(["-t", table, "-C", chain, *rule], check=False).returncode:
            self.run(["-t", table, "-I" if position else "-A", chain, *(["1"] if position else []), *rule])

    def baseline(self) -> None:
        c = self.config
        for table, chain in [("nat", self.DNAT), ("nat", self.SNAT), ("filter", self.FWD)]:
            self.run(["-t", table, "-N", chain], check=False)
        self.ensure("filter", "INPUT", ["-i", c.wan, "-p", "tcp", "--dport", str(c.public_port), "-m", "comment", "--comment", "virvpn-ovpn:listener", "-j", "ACCEPT"], True)
        self.ensure("filter", "INPUT", ["-i", c.tun, "-m", "comment", "--comment", "virvpn-ovpn:host-isolation", "-j", "DROP"], True)
        self.ensure("nat", "PREROUTING", ["-i", c.wan, "-p", "tcp", "-j", self.DNAT], True)
        self.ensure("nat", "POSTROUTING", ["-d", c.network, "-j", self.SNAT], True)
        self.ensure("nat", "POSTROUTING", ["-s", c.network, "-o", c.wan, "-m", "comment", "--comment", "virvpn-ovpn:internet", "-j", "MASQUERADE"])
        self.ensure("filter", "FORWARD", ["-j", self.FWD], True)
        self.ensure("filter", self.FWD, ["-i", c.tun, "-o", c.tun, "-j", "DROP"])
        self.ensure("filter", self.FWD, ["-i", c.tun, "-o", c.wan, "-s", c.network, "-j", "ACCEPT"])
        self.ensure("filter", self.FWD, ["-o", c.tun, "-d", c.network, "-m", "conntrack", "--ctstate", "ESTABLISHED,RELATED", "--ctorigsrc", c.network, "-j", "ACCEPT"], True)
        # Remove only the earlier, overly broad rule in our own managed chain.
        legacy = ["-o", c.tun, "-d", c.network, "-m", "conntrack", "--ctstate", "ESTABLISHED,RELATED", "-j", "ACCEPT"]
        while self.run(["-C", self.FWD, *legacy], check=False).returncode == 0:
            self.run(["-D", self.FWD, *legacy])
        self.ensure("filter", self.FWD, ["-o", c.tun, "-d", c.network, "-j", "DROP"])

    def specs(self, record: dict) -> list[tuple[str, str, list[str]]]:
        if not record.get("remote_public_port"):
            return []
        c = self.config
        source, ip, target = record["remote_allowed_cidr"], record["allocated_ip"], str(record["remote_target_port"])
        base = "virvpn-ovpn:" + record["uuid"]
        return [
            ("nat", self.DNAT, ["-p", "tcp", "-s", source, "--dport", str(record["remote_public_port"]), "-m", "comment", "--comment", base + ":dnat", "-j", "DNAT", "--to-destination", ip + ":" + target]),
            ("nat", self.SNAT, ["-p", "tcp", "-s", source, "-d", ip, "--dport", target, "-m", "comment", "--comment", base + ":snat", "-j", "SNAT", "--to-source", c.gateway]),
            ("filter", self.FWD, ["-i", c.wan, "-o", c.tun, "-p", "tcp", "-s", source, "-d", ip, "--dport", target, "-m", "conntrack", "--ctorigdstport", str(record["remote_public_port"]), "-m", "comment", "--comment", base + ":forward", "-j", "ACCEPT"]),
        ]

    def apply(self, record: dict) -> None:
        for table, chain, rule in self.specs(record):
            self.ensure(table, chain, rule, True)

    def remove(self, record: dict) -> None:
        for table, chain, rule in self.specs(record):
            while self.run(["-t", table, "-C", chain, *rule], check=False).returncode == 0:
                self.run(["-t", table, "-D", chain, *rule])

    def used_ports(self) -> set[int]:
        ports = {self.config.public_port, 9444}
        for path in [Path("/proc/net/tcp"), Path("/proc/net/tcp6")]:
            for line in path.read_text().splitlines()[1:]:
                columns = line.split()
                if columns[3] == "0A":
                    ports.add(int(columns[1].split(":")[1], 16))
        result = subprocess.run(["/usr/sbin/iptables-save", "-t", "nat"], capture_output=True, text=True, timeout=10, check=True)
        for line in result.stdout.splitlines():
            words = shlex.split(line)
            if "DNAT" not in words:
                continue
            for flag in ["--dport", "--dports"]:
                if flag in words:
                    for value in words[words.index(flag) + 1].split(","):
                        bounds = value.replace("-", ":").split(":")
                        if all(part.isdigit() for part in bounds):
                            ports.update(range(max(20000, int(bounds[0])), min(59999, int(bounds[-1])) + 1))
        return ports


class OpenVpnService:
    def __init__(self, config: Config, firewall=None, management=None):
        self.config, self.state = config, State(config)
        self.firewall = firewall or Firewall(config)
        self.management = management or Management(config)

    def identity(self, payload: dict) -> tuple[str, str]:
        account_id = str(uuid.UUID(str(payload.get("account_uuid", ""))))
        identifier = str(payload.get("identifier", ""))
        if not re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9._-]{0,127}", identifier) or identifier == "DEFAULT":
            raise ValueError("Invalid identifier")
        if payload.get("protocol") != "openvpn":
            raise ValueError("Invalid protocol")
        return account_id, identifier

    def public(self, record: dict) -> dict:
        return {key: record.get(key) for key in ["uuid", "identifier", "enabled", "allocated_ip", "remote_public_port", "remote_target_port", "remote_allowed_cidr"]} | {
            "remote_gateway_ip": self.config.gateway, "remote_wan_interface": self.config.wan,
            "remote_rule_status": "active" if record.get("remote_public_port") and record["enabled"] else ("suspended" if record.get("remote_public_port") else None),
        }

    def write_ccd(self, record: dict) -> None:
        mask = str(ipaddress.ip_network(self.config.network).netmask)
        atomic_write(self.config.ccd / record["identifier"], f"ifconfig-push {record['allocated_ip']} {mask}\n", 0o600)

    def create(self, payload: dict) -> dict:
        account_id, identifier = self.identity(payload)
        uuid.UUID(str(payload.get("idempotency_key", "")))
        password = payload.get("password")
        if not isinstance(password, str) or not 12 <= len(password) <= 255 or any(ord(char) < 32 for char in password):
            raise ValueError("Invalid password")
        with self.state.locked():
            state = self.state.read()
            existing = state["accounts"].get(account_id)
            if existing:
                if existing["identifier"] != identifier or not password_matches(existing, password) or not existing["enabled"]:
                    raise ValueError("Account conflict")
                self.write_ccd(existing)
                self.firewall.apply(existing)
                return self.public(existing)
            if any(row["identifier"] == identifier for row in state["accounts"].values()):
                raise ValueError("Identifier already exists")
            used_ips = {row["allocated_ip"] for row in state["accounts"].values()}
            requested_ip = payload.get("allocated_ip")
            start, end = int(ipaddress.ip_address(self.config.pool_start)), int(ipaddress.ip_address(self.config.pool_end))
            address = next((str(ipaddress.ip_address(number)) for number in range(start, end + 1) if str(ipaddress.ip_address(number)) not in used_ips), None)
            if requested_ip:
                candidate = ipaddress.ip_address(str(requested_ip))
                if candidate.version != 4 or not start <= int(candidate) <= end or str(candidate) in used_ips:
                    raise ValueError("Static address unavailable")
                address = str(candidate)
            if not address:
                raise ValueError("Static address pool exhausted")
            salt = secrets.token_hex(16)
            record = {"uuid": account_id, "identifier": identifier, "allocated_ip": address, "enabled": True,
                      "salt": salt, "password_hash": hashlib.pbkdf2_hmac("sha256", password.encode(), bytes.fromhex(salt), 310_000).hex()}
            target = payload.get("remote_target_port")
            if target is not None:
                source = ipaddress.ip_network(str(payload.get("remote_allowed_cidr", "")), strict=True)
                if source.version != 4 or source.prefixlen != 32 or isinstance(target, bool) or not isinstance(target, int) or not 1 <= target <= 65535:
                    raise ValueError("Invalid remote mapping")
                used_ports = self.firewall.used_ports() | set(state.get("retired_ports", [])) | {row.get("remote_public_port") for row in state["accounts"].values()}
                port = next((port for port in range(20000, 60000) if port not in used_ports), None)
                if port is None:
                    raise ValueError("Remote ports exhausted")
                record.update(remote_target_port=target, remote_public_port=port, remote_allowed_cidr=str(source))
            try:
                self.write_ccd(record)
                self.firewall.apply(record)
                state["accounts"][account_id] = record
                self.state.save(state)
            except Exception:
                try:
                    self.firewall.remove(record)
                finally:
                    (self.config.ccd / identifier).unlink(missing_ok=True)
                raise
            return self.public(record)

    def action(self, payload: dict, action: str) -> dict:
        account_id, identifier = self.identity(payload)
        with self.state.locked():
            state = self.state.read()
            record = state["accounts"].get(account_id)
            if not record:
                if action == "revoke":
                    return {"uuid": account_id, "identifier": identifier, "enabled": False}
                raise ValueError("Managed account not found")
            if record["identifier"] != identifier:
                raise ValueError("Account conflict")
            if action == "activate":
                self.write_ccd(record)
                try:
                    self.firewall.apply(record)
                except Exception:
                    if not record["enabled"]:
                        self.firewall.remove(record)
                    raise
                record["enabled"] = True
                self.state.save(state)
            else:
                record["enabled"] = False
                self.state.save(state)  # Reject new logins before disconnecting an existing session.
                try:
                    self.firewall.remove(record)
                finally:
                    self.management.disconnect(identifier)
                if action == "revoke":
                    (self.config.ccd / identifier).unlink(missing_ok=True)
                    if record.get("remote_public_port"):
                        state.setdefault("retired_ports", []).append(record["remote_public_port"])
                    del state["accounts"][account_id]
                    self.state.save(state)
            return self.public(record)

    def disconnect(self, payload: dict) -> dict:
        account_id, identifier = self.identity(payload)
        record = self.state.read()["accounts"].get(account_id)
        session = str(payload.get("session_id", ""))
        if not record or record["identifier"] != identifier or not re.fullmatch(r"ovpn:[0-9]{1,12}", session):
            raise ValueError("Invalid managed session")
        self.management.disconnect(identifier, session)
        return {"status": "success"}

    def reconcile(self) -> None:
        self.firewall.baseline()
        with self.state.locked():
            for record in self.state.read()["accounts"].values():
                if record["enabled"]:
                    self.write_ccd(record)
                    self.firewall.apply(record)
                else:
                    self.firewall.remove(record)


SERVICE: OpenVpnService | None = None


class OpenVpnHandler(AgentHandler):
    server_version = "virVPN-OpenVPN-Agent/1.0"

    def do_GET(self) -> None:
        if not self.authorized(b""):
            self.reply(HTTPStatus.UNAUTHORIZED, {"message": "invalid request authentication"})
            return
        try:
            if self.endpoint == "/v1/health":
                sessions = SERVICE.management.sessions()
                self.reply(HTTPStatus.OK, {"status": "online", "version": "OpenVPN Linux agent 1.0", "metrics": metrics(), "vpn_services": {"openvpn": {"status": "online", "port": SERVICE.config.public_port, "network": SERVICE.config.network, "sessions": len(sessions)}}})
            elif self.endpoint == "/v1/sessions":
                self.reply(HTTPStatus.OK, {"sessions": SERVICE.management.sessions()})
            elif self.endpoint == "/v1/client-template":
                self.reply(HTTPStatus.OK, {"ca": SERVICE.config.ca.read_text(), "cert_name": SERVICE.config.cert_name})
            else:
                self.reply(HTTPStatus.NOT_FOUND, {"message": "endpoint is not allowlisted"})
        except (OSError, RuntimeError, ValueError):
            self.reply(HTTPStatus.SERVICE_UNAVAILABLE, {"message": "OpenVPN service is unavailable"})

    def do_POST(self) -> None:
        try:
            raw = self.body()
            if not self.authorized(raw):
                self.reply(HTTPStatus.UNAUTHORIZED, {"message": "invalid request authentication"})
                return
            payload = json.loads(raw)
            if not isinstance(payload, dict):
                raise ValueError("Invalid JSON")
            if self.endpoint == "/v1/vpn/accounts":
                data = SERVICE.create(payload)
            elif self.endpoint in {"/v1/vpn/accounts/actions/activate", "/v1/vpn/accounts/actions/suspend", "/v1/vpn/accounts/actions/revoke"}:
                data = SERVICE.action(payload, self.endpoint.rsplit("/", 1)[1])
            elif self.endpoint == "/v1/sessions/disconnect":
                data = SERVICE.disconnect(payload)
            else:
                self.reply(HTTPStatus.NOT_FOUND, {"message": "endpoint is not allowlisted"})
                return
            self.reply(HTTPStatus.OK, {"status": "success", "remote_reference": data.get("uuid"), "data": data})
        except (ValueError, KeyError, TypeError):
            self.reply(HTTPStatus.UNPROCESSABLE_ENTITY, {"message": "OpenVPN request is invalid or conflicts with an existing allocation"})
        except (OSError, RuntimeError, subprocess.SubprocessError):
            self.reply(HTTPStatus.SERVICE_UNAVAILABLE, {"message": "OpenVPN operation failed; inspect the local agent"})


def authenticate(credentials_file: str, config: Config) -> bool:
    try:
        lines = Path(credentials_file).read_text(encoding="utf-8").splitlines()
        if len(lines) != 2 or len(lines[0]) > 128 or len(lines[1]) > 255:
            return False
        state = State(config).read()
        record = next((row for row in state["accounts"].values() if row["identifier"] == lines[0]), None)
        return bool(record and record["enabled"] and password_matches(record, lines[1]))
    except (OSError, ValueError, KeyError):
        return False


def main() -> None:
    global SERVICE
    config = Config()
    if len(sys.argv) == 3 and sys.argv[1] == "auth":
        raise SystemExit(0 if authenticate(sys.argv[2], config) else 1)
    if fcntl is None or len(SHARED_SECRET) < 32:
        raise SystemExit("Linux and a 32-character VPN_AGENT_SHARED_SECRET are required")
    network = ipaddress.ip_network(config.network, strict=True)
    if not all(ipaddress.ip_address(value) in network for value in [config.gateway, config.pool_start, config.pool_end]):
        raise SystemExit("Invalid dedicated VPN network")
    if not all(re.fullmatch(r"[A-Za-z0-9_.-]{1,15}", value) for value in [config.wan, config.tun]):
        raise SystemExit("Invalid interface name")
    SERVICE = OpenVpnService(config)
    SERVICE.reconcile()
    ThreadingHTTPServer(("127.0.0.1", int(os.getenv("VPN_AGENT_PORT", "9444"))), OpenVpnHandler).serve_forever()


if __name__ == "__main__":
    main()
