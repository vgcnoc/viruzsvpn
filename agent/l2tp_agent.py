#!/usr/bin/env python3
"""Restricted control agent for the virVPN L2TP/IPsec service.

Only accounts in the explicit virVPN block of /etc/ppp/chap-secrets are ever
changed. Existing PPP/L2TP credentials outside that block are preserved.
"""

import contextlib
import fcntl
import json
import os
import re
import subprocess
import tempfile
import threading
import uuid
from dataclasses import dataclass
from http import HTTPStatus
from http.server import ThreadingHTTPServer
from pathlib import Path

from vpn_agent import AgentHandler, metrics

BEGIN = "# virVPN managed L2TP accounts - do not edit"
END = "# end virVPN managed L2TP accounts"
IDENTIFIER = re.compile(r"^[A-Za-z0-9._-]{1,128}$")


@dataclass
class Config:
    state: Path = Path(os.getenv("VV_L2TP_STATE", "/var/lib/virvpn-l2tp/accounts.json"))
    chap_secrets: Path = Path(os.getenv("VV_L2TP_CHAP_SECRETS", "/etc/ppp/chap-secrets"))
    ppp_server: str = os.getenv("VV_L2TP_PPP_SERVER", "LinuxVPN")
    public_ip: str = os.getenv("VV_L2TP_PUBLIC_IP", "")
    agent_port: int = int(os.getenv("VPN_AGENT_PORT", "9447"))


def atomic_write(path: Path, content: str, mode: int = 0o600) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
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


class State:
    def __init__(self, config: Config):
        self.config = config
        self.thread_lock = threading.RLock()

    @contextlib.contextmanager
    def locked(self):
        with self.thread_lock, self.config.state.with_suffix(".lock").open("a+") as lock:
            fcntl.flock(lock, fcntl.LOCK_EX)
            try:
                yield
            finally:
                fcntl.flock(lock, fcntl.LOCK_UN)

    def read(self) -> dict:
        if not self.config.state.exists():
            return {"version": 1, "accounts": {}}
        state = json.loads(self.config.state.read_text(encoding="utf-8"))
        if state.get("version") != 1 or not isinstance(state.get("accounts"), dict):
            raise RuntimeError("L2TP account state is invalid")
        return state

    def save(self, state: dict) -> None:
        atomic_write(self.config.state, json.dumps(state, separators=(",", ":")))


class L2tpService:
    def __init__(self, config: Config):
        self.config, self.state = config, State(config)

    def identity(self, payload: dict) -> tuple[str, str]:
        account_id = str(uuid.UUID(str(payload.get("account_uuid", ""))))
        identifier = str(payload.get("identifier", ""))
        if payload.get("protocol") != "l2tp-ipsec" or not IDENTIFIER.fullmatch(identifier):
            raise ValueError("Invalid L2TP account")
        return account_id, identifier

    @staticmethod
    def password(payload: dict) -> str:
        value = payload.get("password")
        if not isinstance(value, str) or not 12 <= len(value) <= 255 or not value.isprintable() or any(char.isspace() for char in value):
            raise ValueError("L2TP passwords must be 12-255 printable non-space characters")
        return value

    def unmanaged_identifiers(self, source: str) -> set[str]:
        result, managed = set(), False
        for line in source.splitlines():
            if line == BEGIN:
                managed = True
                continue
            if line == END:
                managed = False
                continue
            if managed or not line.strip() or line.lstrip().startswith("#"):
                continue
            name = line.split(maxsplit=1)[0]
            if IDENTIFIER.fullmatch(name):
                result.add(name)
        return result

    def render(self, records: dict) -> str:
        source = self.config.chap_secrets.read_text(encoding="utf-8") if self.config.chap_secrets.exists() else ""
        lines, output, in_managed = source.splitlines(), [], False
        for line in lines:
            if line == BEGIN:
                if in_managed:
                    raise RuntimeError("L2TP managed block is invalid")
                in_managed = True
                continue
            if line == END:
                if not in_managed:
                    raise RuntimeError("L2TP managed block is invalid")
                in_managed = False
                continue
            if not in_managed:
                output.append(line)
        if in_managed:
            raise RuntimeError("L2TP managed block has no closing marker")
        while output and not output[-1].strip():
            output.pop()
        output += ["", BEGIN]
        for record in sorted(records.values(), key=lambda value: value["identifier"]):
            if record.get("enabled"):
                output += ["# virVPN account " + record["uuid"], f'{record["identifier"]} {self.config.ppp_server} {record["password"]} *']
        output += [END, ""]
        return "\n".join(output)

    def write_accounts(self, records: dict) -> None:
        atomic_write(self.config.chap_secrets, self.render(records), 0o600)

    @staticmethod
    def public(record: dict) -> dict:
        return {"uuid": record["uuid"], "identifier": record["identifier"], "enabled": record["enabled"]}

    def create(self, payload: dict) -> dict:
        account_id, identifier = self.identity(payload)
        uuid.UUID(str(payload.get("idempotency_key", "")))
        password = self.password(payload)
        with self.state.locked():
            state = self.state.read()
            record = state["accounts"].get(account_id)
            if record:
                if record["identifier"] != identifier or record["password"] != password or not record["enabled"]:
                    raise ValueError("L2TP account conflicts with its existing state")
                self.write_accounts(state["accounts"])
                return self.public(record)
            source = self.config.chap_secrets.read_text(encoding="utf-8") if self.config.chap_secrets.exists() else ""
            if identifier in self.unmanaged_identifiers(source) or any(row["identifier"] == identifier for row in state["accounts"].values()):
                raise ValueError("L2TP username is already used by an existing server account")
            record = {"uuid": account_id, "identifier": identifier, "password": password, "enabled": True}
            state["accounts"][account_id] = record
            self.write_accounts(state["accounts"])
            self.state.save(state)
            return self.public(record)

    def action(self, payload: dict, action: str) -> dict:
        account_id, identifier = self.identity(payload)
        with self.state.locked():
            state = self.state.read()
            record = state["accounts"].get(account_id)
            if not record:
                if action == "revoke":
                    return {"uuid": account_id, "identifier": identifier, "enabled": False}
                raise ValueError("Managed L2TP account was not found")
            if record["identifier"] != identifier:
                raise ValueError("L2TP account conflict")
            if action == "activate":
                record["enabled"] = True
            elif action in {"suspend", "revoke"}:
                record["enabled"] = False
            else:
                raise ValueError("Invalid L2TP action")
            self.write_accounts(state["accounts"])
            if action == "revoke":
                del state["accounts"][account_id]
            self.state.save(state)
            return self.public(record)

    def health(self) -> dict:
        def active(service: str) -> bool:
            return subprocess.run(["/usr/bin/systemctl", "is-active", "--quiet", service], check=False).returncode == 0
        l2tp = active("xl2tpd.service")
        ipsec = active("strongswan-starter.service") or active("strongswan.service")
        configured = False
        if ipsec:
            status = subprocess.run(["/usr/sbin/ipsec", "statusall"], check=False, capture_output=True, text=True, timeout=5)
            configured = status.returncode == 0 and "virvpn-l2tp:" in status.stdout
        online = l2tp and ipsec and configured
        return {"status": "online" if online else "degraded", "version": "virVPN L2TP/IPsec agent 1.0", "metrics": metrics(),
                "vpn_services": {"l2tp-ipsec": {"status": "online" if online else "degraded", "port": 1701, "ipsec": ipsec, "configured": configured, "managed_accounts": len(self.state.read()["accounts"]), "endpoint": self.config.public_ip}}}


SERVICE: L2tpService | None = None


class L2tpHandler(AgentHandler):
    server_version = "virVPN-L2TP-Agent/1.0"

    def do_GET(self) -> None:
        if not self.authorized(b""):
            self.reply(HTTPStatus.UNAUTHORIZED, {"message": "invalid request authentication"})
            return
        if self.endpoint != "/v1/health":
            self.reply(HTTPStatus.NOT_FOUND, {"message": "endpoint is not allowlisted"})
            return
        try:
            self.reply(HTTPStatus.OK, SERVICE.health())
        except (OSError, RuntimeError, ValueError, subprocess.SubprocessError):
            self.reply(HTTPStatus.SERVICE_UNAVAILABLE, {"message": "L2TP/IPsec service is unavailable"})

    def do_POST(self) -> None:
        try:
            raw = self.body()
            if not self.authorized(raw):
                self.reply(HTTPStatus.UNAUTHORIZED, {"message": "invalid request authentication"})
                return
            if self.endpoint not in {"/v1/vpn/accounts", "/v1/vpn/accounts/actions/activate", "/v1/vpn/accounts/actions/suspend", "/v1/vpn/accounts/actions/revoke"}:
                self.reply(HTTPStatus.NOT_FOUND, {"message": "endpoint is not allowlisted"})
                return
            payload = json.loads(raw)
            if not isinstance(payload, dict):
                raise ValueError("Invalid JSON")
            if self.endpoint == "/v1/vpn/accounts":
                result, action = SERVICE.create(payload), "provisioned"
            else:
                action = self.endpoint.rsplit("/", 1)[-1]
                result = SERVICE.action(payload, action)
            self.reply(HTTPStatus.OK, {"status": "success", "action": action, "remote_reference": "l2tp:" + result["uuid"], "data": result})
        except (ValueError, json.JSONDecodeError):
            self.reply(HTTPStatus.UNPROCESSABLE_ENTITY, {"message": "invalid L2TP account request"})
        except (OSError, RuntimeError):
            self.reply(HTTPStatus.SERVICE_UNAVAILABLE, {"message": "L2TP account operation failed on the agent"})


def main() -> None:
    global SERVICE
    config = Config()
    if not IDENTIFIER.fullmatch(config.ppp_server) or not config.public_ip:
        raise SystemExit("VV_L2TP_PPP_SERVER and VV_L2TP_PUBLIC_IP are required")
    SERVICE = L2tpService(config)
    ThreadingHTTPServer(("127.0.0.1", config.agent_port), L2tpHandler).serve_forever()


if __name__ == "__main__":
    main()
