import json
import hashlib
import hmac
import tempfile
import threading
import time
import unittest
import urllib.error
import urllib.request
import uuid
from http.server import ThreadingHTTPServer
from pathlib import Path
from unittest.mock import patch

from openvpn_agent import Config, Management, OpenVpnHandler, OpenVpnService, authenticate


class FakeFirewall:
    def __init__(self):
        self.rules = set()
        self.fail = False

    def used_ports(self):
        return {20000, 1196}

    def apply(self, record):
        if record.get("remote_public_port"):
            self.rules.add(record["uuid"])
        if self.fail:
            raise RuntimeError("simulated firewall error")

    def remove(self, record):
        self.rules.discard(record["uuid"])


class FakeManagement:
    def __init__(self):
        self.disconnected = []

    def disconnect(self, identifier, session_id=None):
        self.disconnected.append((identifier, session_id))


class OpenVpnAgentTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        root = Path(self.temporary.name)
        (root / "ccd").mkdir()
        self.config = Config(state=root / "accounts.json", ccd=root / "ccd", pool_end="10.77.0.11")
        self.firewall, self.management = FakeFirewall(), FakeManagement()
        self.service = OpenVpnService(self.config, self.firewall, self.management)
        self.payload = {"protocol": "openvpn", "account_uuid": str(uuid.uuid4()), "idempotency_key": str(uuid.uuid4()),
                        "identifier": "customer-001", "password": "unique-password-123", "remote_target_port": 8291,
                        "remote_allowed_cidr": "203.0.113.10/32"}

    def tearDown(self):
        self.temporary.cleanup()

    def test_create_retry_uses_same_address_and_port_without_storing_plaintext_password(self):
        result = self.service.create(self.payload)
        self.assertEqual(result, self.service.create(self.payload))
        self.assertEqual(result["allocated_ip"], "10.77.0.10")
        self.assertEqual(result["remote_public_port"], 20001)
        self.assertNotIn("password", json.dumps(result))
        self.assertNotIn(self.payload["password"], self.config.state.read_text())
        credentials = self.config.state.parent / "credentials"
        credentials.write_text(self.payload["identifier"] + "\n" + self.payload["password"] + "\n")
        self.assertTrue(authenticate(str(credentials), self.config))
        credentials.write_text(self.payload["identifier"] + "\nwrong-password\n")
        self.assertFalse(authenticate(str(credentials), self.config))

    def test_suspend_activate_and_revoke_preserve_ownership_and_remove_remote_rules(self):
        self.service.create(self.payload)
        self.service.action(self.payload, "suspend")
        self.assertEqual(self.firewall.rules, set())
        self.assertFalse(self.service.state.read()["accounts"][self.payload["account_uuid"]]["enabled"])
        self.assertEqual(self.management.disconnected, [("customer-001", None)])
        self.service.action(self.payload, "activate")
        self.assertIn(self.payload["account_uuid"], self.firewall.rules)
        self.service.action(self.payload, "revoke")
        self.service.action(self.payload, "revoke")
        self.assertEqual(self.service.state.read()["accounts"], {})
        self.assertFalse((self.config.ccd / "customer-001").exists())
        allocation = self.service.create(self.payload | {"account_uuid": str(uuid.uuid4()), "identifier": "customer-002"})
        self.assertEqual(allocation["remote_public_port"], 20002)

    def test_failed_activation_does_not_leave_remote_rules_enabled(self):
        self.service.create(self.payload)
        self.service.action(self.payload, "suspend")
        self.firewall.fail = True
        with self.assertRaises(RuntimeError):
            self.service.action(self.payload, "activate")
        self.assertFalse(self.service.state.read()["accounts"][self.payload["account_uuid"]]["enabled"])
        self.assertEqual(self.firewall.rules, set())

    def test_partial_failure_rolls_back_rules_and_ccd_and_does_not_reserve_an_account(self):
        self.firewall.fail = True
        with self.assertRaises(RuntimeError):
            self.service.create(self.payload)
        self.assertEqual(self.firewall.rules, set())
        self.assertEqual(self.service.state.read()["accounts"], {})
        self.assertFalse((self.config.ccd / "customer-001").exists())

    def test_public_source_and_ccd_identifier_are_strictly_validated(self):
        for field, value in [("remote_allowed_cidr", "0.0.0.0/0"), ("identifier", "../escape"), ("identifier", "DEFAULT")]:
            with self.assertRaises(ValueError):
                self.service.create(self.payload | {field: value})
        self.assertEqual(self.service.state.read()["accounts"], {})

    def test_duplicate_username_and_ip_cannot_be_assigned_to_another_account(self):
        self.service.create(self.payload)
        with self.assertRaises(ValueError):
            self.service.create(self.payload | {"account_uuid": str(uuid.uuid4())})
        with self.assertRaises(ValueError):
            self.service.create(self.payload | {"account_uuid": str(uuid.uuid4()), "identifier": "customer-002", "allocated_ip": "10.77.0.10"})

    def test_management_status_maps_client_ids_to_owned_sessions(self):
        class StatusManagement(Management):
            def command(self, command, status=False):
                return "HEADER\tCLIENT_LIST\tCommon Name\tReal Address\tVirtual Address\tBytes Received\tBytes Sent\tUsername\tClient ID\nCLIENT_LIST\tcustomer-001\t203.0.113.10:55000\t10.77.0.10\t123\t456\tcustomer-001\t8\nEND\n"
        sessions = StatusManagement(self.config).sessions()
        self.assertEqual(sessions[0]["id"], "ovpn:8")
        self.assertEqual(sessions[0]["identifier"], "customer-001")
        self.assertEqual(sessions[0]["bytes_in"], 123)

    def test_http_requires_hmac_and_rejects_replayed_mutations(self):
        secret = 'test-agent-secret-' * 4
        body = json.dumps(self.payload).encode()
        timestamp, nonce = str(int(time.time())), str(uuid.uuid4())
        message = '\n'.join(['POST', '/v1/vpn/accounts', timestamp, nonce, body.decode()]).encode()
        signature = hmac.new(secret.encode(), message, hashlib.sha256).hexdigest()
        with patch('openvpn_agent.SERVICE', self.service), patch('vpn_agent.SHARED_SECRET', secret):
            server = ThreadingHTTPServer(('127.0.0.1', 0), OpenVpnHandler)
            thread = threading.Thread(target=server.serve_forever, daemon=True)
            thread.start()
            try:
                url = 'http://127.0.0.1:' + str(server.server_port) + '/v1/vpn/accounts'
                request = urllib.request.Request(url, body, headers={'X-Agent-Time': timestamp, 'X-Agent-Nonce': nonce, 'X-Agent-Signature': signature})
                with urllib.request.urlopen(request) as response:
                    self.assertEqual(response.status, 200)
                    self.assertNotIn('password_hash', response.read().decode())
                with self.assertRaises(urllib.error.HTTPError) as replay:
                    urllib.request.urlopen(request)
                self.assertEqual(replay.exception.code, 401)
                with self.assertRaises(urllib.error.HTTPError) as unsigned:
                    urllib.request.urlopen(urllib.request.Request(url, body))
                self.assertEqual(unsigned.exception.code, 401)
            finally:
                server.shutdown()
                server.server_close()
                thread.join()


if __name__ == "__main__":
    unittest.main()
