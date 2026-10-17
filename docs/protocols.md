# Protocol support matrix

VPNOPS only exposes a protocol when the registered server declares it in its capabilities. Do not add a protocol merely because the server software could theoretically run it.

| Adapter | Protocol | Provisioning | Authentication | Revoke | Statistics / limitation |
| --- | --- | --- | --- | --- | --- |
| Linux agent | WireGuard | Creates a peer on the agent's fixed interface with a client public key and allocated IPv4 `/32` | WireGuard public/private key pair; private key stays on the client | Removes that peer | Agent exposes interface state and peer count, not per-peer byte accounting |
| RouterOS | PPP | Creates a PPP secret through RouterOS API | Username/password | Removes or disables the PPP secret | Lists PPP secrets and active PPP sessions; no per-account traffic implementation |
| RouterOS | L2TP/IPsec | Creates an L2TP PPP secret | Username/password plus the server-side IPsec configuration | Removes or disables the PPP secret | Same RouterOS PPP limitations |
| RouterOS | SSTP | Creates an SSTP PPP secret | Username/password and server TLS configuration | Removes or disables the PPP secret | Same RouterOS PPP limitations |
| RouterOS | OpenVPN | Creates an OVPN PPP secret | Username/password plus the server-side OVPN/TLS configuration | Removes or disables the PPP secret | Same RouterOS PPP limitations |
| RouterOS | PPTP | Available only if an administrator explicitly declares it | Username/password | Removes or disables the PPP secret | Legacy/insecure: keep disabled by default and use only for constrained migrations |

## WireGuard flow

Generate the key pair on the client. Register only the base64 WireGuard public key and a unique IPv4 address in the agent's configured `VPN_AGENT_WG_ALLOWED_CIDR`. VPNOPS sends that public key to the agent through HMAC-authenticated HTTPS; the agent runs the fixed WireGuard peer action. Build and deliver the client `.conf` file through your own secure process because it contains the client's private key. Suspending/revoking removes the peer. A queued expiry performs the same revoke before changing the local status to `expired`.

## RouterOS PPP flow

Configure RouterOS services, profiles, address pools, certificates, firewall rules, and (where relevant) IPsec before registering it. VPNOPS only manages the PPP secret; it does not alter broad RouterOS network configuration. The entered client password is encrypted briefly for the queued create, never returned, and cleared after success. A suspended secret is set to `disabled=yes`; activation sets it to `disabled=no`; revocation removes it. A failed operation leaves the database status unchanged and records the result in the audit log.

The RouterOS driver does not currently attribute byte counters to individual users, enforce bandwidth/connection limits, generate client profiles, or manage RouterOS service/profile configuration. Keep those fields as commercial metadata until corresponding network enforcement has been implemented and tested.
