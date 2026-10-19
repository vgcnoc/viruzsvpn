#!/usr/bin/env python3
"""Install the dedicated virVPN instance without touching an existing VPN service."""
import hashlib
import hmac
import ipaddress
import json
import os
import secrets
import shutil
import socket
import subprocess
import time
import urllib.request
import uuid
from pathlib import Path


ROOT = Path(__file__).resolve().parent
CONFIG = Path('/etc/virvpn-openvpn')
CODE = Path('/opt/virvpn-openvpn')


def run(arguments):
    result = subprocess.run(arguments, capture_output=True, text=True, timeout=30)
    if result.returncode:
        raise RuntimeError('Installation command failed: ' + arguments[0] + ' ' + ' '.join(arguments[1:3]))
    return result.stdout


def copy(source, destination, mode=0o600):
    shutil.copyfile(source, destination)
    os.chmod(destination, mode)


def install():
    if os.geteuid() != 0:
        raise SystemExit('Run this installer as root.')
    existing = (CONFIG / 'server.conf').exists()
    if not existing:
        for port in [1196, 9444]:
            with socket.socket() as check:
                check.bind(('0.0.0.0', port))
        for route in json.loads(run(['/usr/sbin/ip', '-j', 'route', 'show'])):
            destination = route.get('dst', 'default')
            if destination != 'default' and ipaddress.ip_network(destination, strict=False).overlaps(ipaddress.ip_network('10.77.0.0/24')):
                raise RuntimeError('The dedicated 10.77.0.0/24 network overlaps an existing route.')
    for directory in [CONFIG, CONFIG / 'ccd', CODE, Path('/var/lib/virvpn-openvpn')]:
        directory.mkdir(parents=True, exist_ok=True)
        os.chmod(directory, 0o700)
    for name in ['openvpn_agent.py', 'vpn_agent.py']:
        copy(ROOT / name, CODE / name)
    if not (CONFIG / 'agent.env').exists():
        descriptor = os.open(CONFIG / 'agent.env', os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
        with os.fdopen(descriptor, 'w') as handle:
            handle.write('VPN_AGENT_SHARED_SECRET=' + secrets.token_hex(32) + '\nVPN_AGENT_PORT=9444\n')
    if not (CONFIG / 'server.crt').exists():
        run(['/usr/bin/openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '3650',
             '-keyout', str(CONFIG / 'ca.key'), '-out', str(CONFIG / 'ca.crt'), '-subj', '/CN=virVPN Linux OpenVPN CA',
             '-addext', 'basicConstraints=critical,CA:TRUE', '-addext', 'keyUsage=critical,keyCertSign,cRLSign'])
        run(['/usr/bin/openssl', 'req', '-new', '-newkey', 'rsa:2048', '-nodes', '-keyout', str(CONFIG / 'server.key'),
             '-out', str(CONFIG / 'server.csr'), '-subj', '/CN=vpn2.viruzs.my.id'])
        run(['/usr/bin/openssl', 'x509', '-req', '-days', '825', '-in', str(CONFIG / 'server.csr'),
             '-CA', str(CONFIG / 'ca.crt'), '-CAkey', str(CONFIG / 'ca.key'), '-CAcreateserial',
             '-out', str(CONFIG / 'server.crt'), '-extfile', str(ROOT / 'openvpn-server.ext')])
        for name in ['ca.key', 'server.key']:
            os.chmod(CONFIG / name, 0o600)
    copy(ROOT / 'openvpn-server.conf', CONFIG / 'server.conf')
    for name in ['virvpn-openvpn.service', 'virvpn-openvpn-agent.service']:
        copy(ROOT / name, Path('/etc/systemd/system') / name, 0o644)
    Path('/run/xtables.lock').touch(exist_ok=True)
    if run(['/usr/sbin/sysctl', '-n', 'net.ipv4.ip_forward']).strip() != '1':
        raise RuntimeError('IPv4 forwarding must be enabled before installing this VPN instance.')
    run(['/usr/bin/systemctl', 'daemon-reload'])
    run(['/usr/bin/systemctl', 'enable', '--now', 'virvpn-openvpn.service', 'virvpn-openvpn-agent.service'])
    if existing:
        run(['/usr/bin/systemctl', 'restart', 'virvpn-openvpn.service', 'virvpn-openvpn-agent.service'])
    secret = next(line.split('=', 1)[1] for line in (CONFIG / 'agent.env').read_text().splitlines() if line.startswith('VPN_AGENT_SHARED_SECRET='))
    for attempt in range(20):
        timestamp, nonce = str(int(time.time())), str(uuid.uuid4())
        signature = hmac.new(secret.encode(), '\n'.join(['GET', '/v1/health', timestamp, nonce, '']).encode(), hashlib.sha256).hexdigest()
        request = urllib.request.Request('http://127.0.0.1:9444/v1/health', headers={'X-Agent-Time': timestamp, 'X-Agent-Nonce': nonce, 'X-Agent-Signature': signature})
        try:
            with urllib.request.urlopen(request, timeout=3) as response:
                health = json.load(response)
            if health.get('status') == 'online':
                print(json.dumps({'status': 'online', 'vpn_port': 1196, 'agent_bind': '127.0.0.1:9444', 'network': '10.77.0.0/24'}))
                return
        except (OSError, ValueError):
            time.sleep(0.5)
    raise RuntimeError('The new service did not become ready; inspect its systemd journal.')


if __name__ == '__main__':
    install()
