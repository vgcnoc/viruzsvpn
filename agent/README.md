# virVPN Linux agents

## Dedicated OpenVPN instance (same VPS as Laravel)

`openvpn_agent.py` manages a separate OpenVPN service, not existing VPN users or
`chap-secrets`. The supplied deployment is specific to VPS `139.190.96.74`:
TCP **1196**, `tun-virvpn`, dedicated `10.77.0.0/24`, gateway `10.77.0.1`, and
static client pool `.10`–`.254`. Existing TCP 1195 and L2TP remain unchanged.

Requirements: Ubuntu/Linux, Python 3.10+, OpenVPN 2.5+, OpenSSL, iproute2,
iptables, systemd, IPv4 forwarding, WAN `eth0`, and unused ports/subnet above.
Review configuration, certificate SANs, network conflicts, and firewall policy
before installing on a different host. This is not a generic remote-agent installer.

From a reviewed checkout on this VPS:

```bash
sudo python3 agent/install_openvpn.py
sudo php agent/register_openvpn.php
sudo python3 agent/verify_openvpn.py
```

The installer creates its own CA/server private keys on the VPS, a root-only
HMAC secret, CCD directory, hashed-password state, and two systemd units. A rerun
preserves CA, keys, secret and accounts but restarts only the new services;
schedule reruns around active new-instance clients. The agent binds exclusively
to **127.0.0.1:9444**, accepts signed/replay-protected fixed operations, and does
not expose a shell or accept arbitrary paths/commands. Laravel must be on this
same VPS. Registration stores the secret encrypted, without displaying it.

For a second VPN VPS, keep the agent loopback-only and create an SSH reverse
tunnel to an unused loopback port on the application VPS. Restrict the tunnel
key on the application VPS with `permitlisten` to that one port, set strict host
key checking, and use `virvpn-openvpn-remote-tunnel.service`. Register it using
`register_remote_openvpn.php`; it consumes the agent secret on standard input
instead of writing the secret into an application file. Do not expose TCP 9444
to the Internet. The customer-facing endpoint remains the remote VPS address.

In **Servers**, the new adapter is **Linux OpenVPN (local agent)**. Manual accounts
can use a chosen static pool IP or automatic allocation. For customer sales, edit
a **Paket VPN**, enable `openvpn`, and allow this server. Prices and package
entitlements are never changed by installation. Normal invoice/payment approval
then queues account creation. Download **OpenVPN (.ovpn)** from active account
details or the customer service card, import it into an OpenVPN client, and enter
the username and password chosen at creation. The profile verifies the server
certificate; it includes only the public CA, never the password/private key.
MikroTik v6 OpenVPN import compatibility is not provided by this generic profile.

Remote TCP access is optional. It allocates a unique public port in 20000–59999
and installs tagged DNAT/SNAT/forward rules restricted to the specified `/32`
source. Retired public ports remain reserved so old database allocations are not
reused. Existing listeners/DNAT rules are checked for conflicts. Suspend disables
login, removes the account rules and disconnects its sessions; activate restores
them; revoke also removes the managed account. Client-side LAN forwarding still
requires gateway configuration when the target device is behind the VPN client.
The dedicated chains isolate clients from each other and VPS host services.

Monitoring supports live sessions, disconnect, suspend, activate, and revoke.
The acceptance check creates a temporary account, establishes a real local TLS
VPN connection without changing production routes, tests lifecycle/NAT cleanup,
then removes its own account/rules. Unit tests: `python3 -m unittest -v
test_openvpn_agent` from `agent/`. Back up root-only state, CCD, CA/keys and the
application database together. Package bandwidth/data quotas are metadata; this
adapter does not enforce traffic shaping or byte quotas.

## Linux WireGuard agent

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
