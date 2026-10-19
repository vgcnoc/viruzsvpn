#!/usr/bin/env python3
"""Exercise the dedicated instance with a temporary account, then remove it."""
import hashlib
import hmac
import json
import os
import secrets
import subprocess
import tempfile
import time
import urllib.request
import uuid
from pathlib import Path


def verify():
    secret = next(line.split('=', 1)[1] for line in Path('/etc/virvpn-openvpn/agent.env').read_text().splitlines() if line.startswith('VPN_AGENT_SHARED_SECRET='))

    def request(method, path, payload=None):
        body = json.dumps(payload).encode() if payload is not None else b''
        timestamp, nonce = str(int(time.time())), str(uuid.uuid4())
        message = '\n'.join([method, path, timestamp, nonce, body.decode()]).encode()
        signature = hmac.new(secret.encode(), message, hashlib.sha256).hexdigest()
        call = urllib.request.Request('http://127.0.0.1:9444' + path, body if payload is not None else None, method=method,
            headers={'X-Agent-Time': timestamp, 'X-Agent-Nonce': nonce, 'X-Agent-Signature': signature, 'Content-Type': 'application/json'})
        with urllib.request.urlopen(call, timeout=40) as response:
            return json.load(response)

    identity = {'protocol': 'openvpn', 'account_uuid': str(uuid.uuid4()), 'identifier': 'virvpn-smoke-' + secrets.token_hex(4)}
    password = secrets.token_urlsafe(24)
    created = False
    process = None
    result = {}
    # systemctl start/restart returns before a simple service opens its listener.
    for attempt in range(20):
        try:
            if request('GET', '/v1/health').get('status') == 'online':
                break
        except (OSError, ValueError):
            time.sleep(0.5)
    else:
        raise RuntimeError('The dedicated OpenVPN agent is not ready.')
    with tempfile.TemporaryDirectory(prefix='virvpn-ovpn-acceptance-') as directory:
        root = Path(directory)
        try:
            allocation = request('POST', '/v1/vpn/accounts', identity | {'idempotency_key': str(uuid.uuid4()), 'password': password,
                'remote_target_port': 8291, 'remote_allowed_cidr': '139.190.96.74/32'})['data']
            created = True
            auth = root / 'auth.txt'
            auth.write_text(identity['identifier'] + '\n' + password + '\n')
            os.chmod(auth, 0o600)
            log = (root / 'client.log').open('w+')
            process = subprocess.Popen(['/usr/sbin/openvpn', '--client', '--dev', 'tun-vv-test', '--proto', 'tcp-client',
                '--remote', '127.0.0.1', '1196', '--ca', '/etc/virvpn-openvpn/ca.crt', '--remote-cert-tls', 'server',
                '--verify-x509-name', 'vpn2.viruzs.my.id', 'name', '--auth-user-pass', str(auth), '--auth-nocache',
                '--cipher', 'AES-256-CBC', '--data-ciphers', 'AES-256-GCM:AES-256-CBC', '--auth', 'SHA1',
                '--tls-version-min', '1.2', '--ifconfig-noexec', '--route-noexec', '--connect-timeout', '5',
                '--connect-retry-max', '1', '--verb', '3'], stdout=log, stderr=subprocess.STDOUT)
            connected = False
            for attempt in range(30):
                sessions = request('GET', '/v1/sessions')['sessions']
                if any(row['identifier'] == identity['identifier'] for row in sessions):
                    connected = True
                    break
                if process.poll() is not None:
                    break
                time.sleep(0.5)
            result['tls_authentication_and_monitoring'] = connected
            result['static_ip'] = allocation['allocated_ip']
            result['remote_port_allocated'] = bool(allocation.get('remote_public_port'))
            request('POST', '/v1/vpn/accounts/actions/suspend', identity)
            for attempt in range(10):
                if not any(row['identifier'] == identity['identifier'] for row in request('GET', '/v1/sessions')['sessions']):
                    result['suspend_disconnected_session'] = True
                    break
                time.sleep(0.3)
            request('POST', '/v1/vpn/accounts/actions/activate', identity)
            result['activate_succeeded'] = True
        finally:
            if process and process.poll() is None:
                process.terminate()
                try:
                    process.wait(timeout=5)
                except subprocess.TimeoutExpired:
                    process.kill()
                    process.wait(timeout=3)
            if created:
                request('POST', '/v1/vpn/accounts/actions/revoke', identity)
                state = json.loads(Path('/var/lib/virvpn-openvpn/accounts.json').read_text())
                result['test_account_removed'] = identity['account_uuid'] not in state['accounts']
                rules = subprocess.run(['/usr/sbin/iptables-save'], capture_output=True, text=True, timeout=10, check=True).stdout
                result['test_firewall_rules_removed'] = identity['account_uuid'] not in rules
            if 'log' in locals():
                log.close()
    print(json.dumps(result, indent=2))
    if not all(result.get(key) for key in ['tls_authentication_and_monitoring', 'remote_port_allocated', 'suspend_disconnected_session', 'activate_succeeded', 'test_account_removed', 'test_firewall_rules_removed']):
        raise SystemExit(2)


if __name__ == '__main__':
    verify()
