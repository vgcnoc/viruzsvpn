#!/usr/bin/env python3
"""Restricted HTTPS WireGuard control agent for VPNOPS.

Only named, validated peer operations are possible. HTTP input can never
choose a binary, shell fragment, WireGuard interface, or configuration path.
"""

import hashlib
import hmac
import ipaddress
import json
import os
import re
import shutil
import ssl
import subprocess
import sys
import threading
import time
from collections import deque
from http import HTTPStatus
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import urlsplit

BIND = os.getenv("VPN_AGENT_BIND", "127.0.0.1")
PORT = int(os.getenv("VPN_AGENT_PORT", "9443"))
SHARED_SECRET = os.getenv("VPN_AGENT_SHARED_SECRET", "")
CERT = os.getenv("VPN_AGENT_TLS_CERT", "")
KEY = os.getenv("VPN_AGENT_TLS_KEY", "")
WG_INTERFACE = os.getenv("VPN_AGENT_WG_INTERFACE", "")
WG_ALLOWED_CIDR = os.getenv("VPN_AGENT_WG_ALLOWED_CIDR", "")
WG_SERVER_PUBLIC_KEY = os.getenv("VPN_AGENT_WG_SERVER_PUBLIC_KEY", "")
PERSIST_WIREGUARD = os.getenv("VPN_AGENT_WG_PERSIST", "0") == "1"
PERSIST_MODE = os.getenv("VPN_AGENT_WG_PERSIST_MODE", "wg-quick")
MAX_SKEW_SECONDS = 60
MAX_BODY_BYTES = 16_384
SEEN_NONCES: deque[tuple[float, str]] = deque()
NONCE_LOCK = threading.Lock()
IDENTIFIER = re.compile(r"^[A-Za-z0-9._-]{1,128}$")
WIREGUARD_PUBLIC_KEY = re.compile(r"^[A-Za-z0-9+/]{43}=$")
WG = "/usr/bin/wg"
WG_QUICK = "/usr/bin/wg-quick"


def purge_nonces() -> None:
    threshold = time.time() - MAX_SKEW_SECONDS
    while SEEN_NONCES and SEEN_NONCES[0][0] < threshold:
        SEEN_NONCES.popleft()


def metrics() -> dict:
    usage = shutil.disk_usage("/")
    result = {"disk": {"total": usage.total, "used": usage.used, "free": usage.free}}
    if hasattr(os, "getloadavg"):
        result["load_average_1m"] = os.getloadavg()[0]
    try:
        with open("/proc/meminfo", encoding="utf-8") as meminfo:
            values = dict(line.split(":", 1) for line in meminfo if ":" in line)
        result["memory"] = {"total_kb": int(values["MemTotal"].split()[0]), "available_kb": int(values["MemAvailable"].split()[0])}
    except (FileNotFoundError, KeyError, ValueError):
        result["memory"] = {"status": "unavailable"}
    return result


def run_wireguard(arguments: list[str]) -> None:
    """Run only a fixed system binary with arguments built from strict validators."""
    completed = subprocess.run([WG, *arguments], check=False, capture_output=True, text=True, timeout=10)
    if completed.returncode != 0:
        raise RuntimeError("WireGuard command failed")


def peer_exists(public_key: str) -> bool:
    completed = subprocess.run([WG, "show", WG_INTERFACE, "peers"], check=False, capture_output=True, text=True, timeout=10)
    if completed.returncode != 0:
        raise RuntimeError("WireGuard interface is unavailable")
    return public_key in {line.strip() for line in completed.stdout.splitlines() if line.strip()}


def persist_wireguard() -> None:
    if not PERSIST_WIREGUARD:
        return
    command = ["/usr/local/sbin/virvpn-wireguard-persist", WG_INTERFACE] if PERSIST_MODE == "virvpn" else [WG_QUICK, "save", WG_INTERFACE]
    completed = subprocess.run(command, check=False, capture_output=True, text=True, timeout=15)
    if completed.returncode != 0:
        raise RuntimeError("WireGuard configuration could not be persisted")


def wireguard_service() -> dict:
    """Expose actual interface state without accepting any user-controlled command."""
    try:
        completed = subprocess.run([WG, "show", WG_INTERFACE, "peers"], check=False, capture_output=True, text=True, timeout=5)
        if completed.returncode != 0:
            return {"status": "degraded", "interface": WG_INTERFACE, "peer_network": WG_ALLOWED_CIDR}
        return {
            "status": "online", "interface": WG_INTERFACE, "peer_network": WG_ALLOWED_CIDR,
            "peer_count": len([line for line in completed.stdout.splitlines() if line.strip()]),
            "persistence": PERSIST_WIREGUARD,
            **({"server_public_key": WG_SERVER_PUBLIC_KEY} if WIREGUARD_PUBLIC_KEY.fullmatch(WG_SERVER_PUBLIC_KEY) else {}),
        }
    except (OSError, subprocess.SubprocessError):
        return {"status": "degraded", "interface": WG_INTERFACE, "peer_network": WG_ALLOWED_CIDR}


def validate_wireguard_request(request: object) -> dict:
    if not isinstance(request, dict):
        raise ValueError("invalid JSON object")
    if request.get("protocol") != "wireguard" or not IDENTIFIER.fullmatch(str(request.get("identifier", ""))):
        raise ValueError("invalid WireGuard account")
    if not isinstance(request.get("idempotency_key"), str) or not request["idempotency_key"]:
        raise ValueError("missing idempotency key")
    public_key = request.get("peer_public_key")
    if not isinstance(public_key, str) or not WIREGUARD_PUBLIC_KEY.fullmatch(public_key):
        raise ValueError("invalid WireGuard public key")
    try:
        address = ipaddress.ip_address(str(request.get("allocated_ip", "")))
    except ValueError as exc:
        raise ValueError("invalid allocated IP") from exc
    if address.version != 4 or address not in ipaddress.ip_network(WG_ALLOWED_CIDR, strict=False):
        raise ValueError("allocated IP is outside the configured WireGuard peer network")
    return {"public_key": public_key, "allowed_ip": f"{address}/32"}


def apply_peer(payload: dict) -> None:
    run_wireguard(["set", WG_INTERFACE, "peer", payload["public_key"], "allowed-ips", payload["allowed_ip"]])
    persist_wireguard()


def remove_peer(payload: dict) -> None:
    if peer_exists(payload["public_key"]):
        run_wireguard(["set", WG_INTERFACE, "peer", payload["public_key"], "remove"])
        persist_wireguard()


class AgentHandler(BaseHTTPRequestHandler):
    server_version = "VPNOPS-Agent/1.0"

    def log_message(self, format: str, *args: object) -> None:
        # Never log request headers or bodies; they carry account identifiers.
        sys.stderr.write("agent %s - %s\n" % (self.client_address[0], format % args))

    @property
    def endpoint(self) -> str:
        return urlsplit(self.path).path

    def body(self) -> bytes:
        length = int(self.headers.get("Content-Length", "0"))
        if length < 0 or length > MAX_BODY_BYTES:
            raise ValueError("body too large")
        return self.rfile.read(length)

    def authorized(self, raw_body: bytes) -> bool:
        # Query strings are not part of the signed API contract.
        if urlsplit(self.path).query:
            return False
        timestamp = self.headers.get("X-Agent-Time", "")
        nonce = self.headers.get("X-Agent-Nonce", "")
        signature = self.headers.get("X-Agent-Signature", "")
        try:
            if abs(time.time() - int(timestamp)) > MAX_SKEW_SECONDS or not nonce or len(nonce) > 128:
                return False
        except ValueError:
            return False
        try:
            body_text = raw_body.decode("utf-8")
        except UnicodeDecodeError:
            return False
        message = "\n".join([self.command, self.endpoint, timestamp, nonce, body_text]).encode("utf-8")
        expected = hmac.new(SHARED_SECRET.encode("utf-8"), message, hashlib.sha256).hexdigest()
        if not hmac.compare_digest(expected, signature):
            return False
        with NONCE_LOCK:
            purge_nonces()
            if any(existing == nonce for _, existing in SEEN_NONCES):
                return False
            SEEN_NONCES.append((time.time(), nonce))
        return True

    def reply(self, status: HTTPStatus, payload: dict) -> None:
        encoded = json.dumps(payload, separators=(",", ":")).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(encoded)))
        self.send_header("Cache-Control", "no-store")
        self.end_headers()
        self.wfile.write(encoded)

    def do_GET(self) -> None:
        if not self.authorized(b""):
            self.reply(HTTPStatus.UNAUTHORIZED, {"message": "invalid request authentication"})
            return
        if self.endpoint == "/v1/health":
            self.reply(HTTPStatus.OK, {"status": "online", "metrics": metrics(), "vpn_services": {"wireguard": wireguard_service()}})
            return
        self.reply(HTTPStatus.NOT_FOUND, {"message": "endpoint is not allowlisted"})

    def do_POST(self) -> None:
        try:
            raw_body = self.body()
        except ValueError:
            self.reply(HTTPStatus.REQUEST_ENTITY_TOO_LARGE, {"message": "body too large"})
            return
        if not self.authorized(raw_body):
            self.reply(HTTPStatus.UNAUTHORIZED, {"message": "invalid request authentication"})
            return
        if self.endpoint not in {"/v1/vpn/accounts", "/v1/vpn/accounts/actions/activate", "/v1/vpn/accounts/actions/suspend", "/v1/vpn/accounts/actions/revoke"}:
            self.reply(HTTPStatus.NOT_FOUND, {"message": "endpoint is not allowlisted"})
            return
        try:
            payload = validate_wireguard_request(json.loads(raw_body))
        except (json.JSONDecodeError, ValueError):
            self.reply(HTTPStatus.UNPROCESSABLE_ENTITY, {"message": "invalid WireGuard request"})
            return
        try:
            if self.endpoint in {"/v1/vpn/accounts", "/v1/vpn/accounts/actions/activate"}:
                apply_peer(payload)
                action = "provisioned" if self.endpoint.endswith("accounts") else "activated"
            else:
                remove_peer(payload)
                action = "suspended" if self.endpoint.endswith("suspend") else "revoked"
            self.reply(HTTPStatus.OK, {"status": "success", "action": action, "remote_reference": payload["public_key"]})
        except (OSError, subprocess.SubprocessError, RuntimeError):
            # Keep command diagnostics on the host; never return them to the caller.
            self.reply(HTTPStatus.SERVICE_UNAVAILABLE, {"status": "failed", "message": "WireGuard operation failed on the agent."})


def main() -> None:
    if not SHARED_SECRET or not CERT or not KEY or not WG_INTERFACE or not WG_ALLOWED_CIDR:
        raise SystemExit("VPN_AGENT_SHARED_SECRET, VPN_AGENT_TLS_CERT, VPN_AGENT_TLS_KEY, VPN_AGENT_WG_INTERFACE, and VPN_AGENT_WG_ALLOWED_CIDR are required")
    if not re.fullmatch(r"[A-Za-z0-9_.-]{1,15}", WG_INTERFACE):
        raise SystemExit("VPN_AGENT_WG_INTERFACE is invalid")
    try:
        network = ipaddress.ip_network(WG_ALLOWED_CIDR, strict=False)
        if network.version != 4:
            raise ValueError
    except ValueError:
        raise SystemExit("VPN_AGENT_WG_ALLOWED_CIDR must be an IPv4 network")
    if not os.path.isfile(WG) or (PERSIST_WIREGUARD and PERSIST_MODE != "virvpn" and not os.path.isfile(WG_QUICK)):
        raise SystemExit("WireGuard tools are not installed at the expected paths")
    httpd = ThreadingHTTPServer((BIND, PORT), AgentHandler)
    context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    context.minimum_version = ssl.TLSVersion.TLSv1_2
    context.load_cert_chain(CERT, KEY)
    httpd.socket = context.wrap_socket(httpd.socket, server_side=True)
    httpd.serve_forever()


if __name__ == "__main__":
    main()
