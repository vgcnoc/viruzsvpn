# VPNOPS Linux WireGuard agent

This is a small dependency-free Python 3 HTTPS service for a single, preconfigured WireGuard interface. It authenticates each control-plane request with HMAC, a 60-second timestamp window, and a one-time nonce. It has no shell, SSH, file browser, generic RouterOS endpoint, or arbitrary command capability.

Its only mutating actions are fixed WireGuard operations:

- create/activate a peer using a validated client public key and an IPv4 `/32` inside the configured peer network;
- suspend/revoke that exact peer; and
- optionally persist the resulting interface configuration with `wg-quick save`.

The request cannot choose a binary, interface name, allowed network, or configuration path. The client private key is never sent to, stored by, or returned from the agent.

## Prerequisites

Prepare a working WireGuard interface on the target Linux host first, for example `wg0` with a server address in `10.66.0.0/24`. Install `wireguard-tools`; the agent intentionally expects `/usr/bin/wg` and, when persistence is on, `/usr/bin/wg-quick`. Run the control plane and agent over a private network or reverse proxy—do not publish the agent port to the Internet.

## Install

1. Copy `vpn_agent.py` to `/opt/vpnops-agent/vpn_agent.py`, owned by root and executable. Create `/etc/vpnops-agent/agent.env`, owned by root and mode `0600`:

   ```ini
   VPN_AGENT_BIND=127.0.0.1
   VPN_AGENT_PORT=9443
   VPN_AGENT_SHARED_SECRET=replace-with-a-long-random-secret-of-at-least-32-characters
   VPN_AGENT_TLS_CERT=/etc/vpnops-agent/tls.crt
   VPN_AGENT_TLS_KEY=/etc/vpnops-agent/tls.key
   VPN_AGENT_WG_INTERFACE=wg0
   VPN_AGENT_WG_ALLOWED_CIDR=10.66.0.0/24
   VPN_AGENT_WG_PERSIST=1
   ```

2. Use a certificate trusted by the Laravel host (or a private CA installed there). If the agent must be reached from another machine, keep its listener private and place an authenticated reverse proxy or private network in front of it.
3. Install `vpn-agent.service` at `/etc/systemd/system/vpn-agent.service`, then run:

   ```bash
   sudo systemctl daemon-reload
   sudo systemctl enable --now vpn-agent
   sudo systemctl status vpn-agent
   ```

4. Register the machine in VPNOPS as **Linux agent**, with the same shared secret, the agent hostname/port, `wireguard` as its supported protocol, and certificate verification enabled. Use the server **Test** button before creating an account.
5. For each client, generate its WireGuard key pair locally. In VPNOPS choose the Linux-agent server, enter the allocated IPv4 address and only the client public key. The agent creates the peer; distribute the separate client configuration by your own secure channel.

The systemd service runs as root only because peer changes require `CAP_NET_ADMIN`. It confines capabilities, makes the filesystem read-only except the explicit WireGuard/config state paths, and prevents privilege escalation. Review the unit against your host hardening standard before deployment.
