# virVPN — VPN management and customer billing

virVPN is a VPN control plane with customer registration, email verification, plan catalog, manual transfer invoices, administrator payment review, and queue-backed service provisioning. The React console calls a Laravel API with a limited driver boundary for server actions.

The customer sales workflow is documented in [docs/customer-billing.md](docs/customer-billing.md). Payment is verified manually; no payment gateway is connected. Production email verification requires a configured mail transport. Until then, `MAIL_MAILER=log` records verification links only in server logs.

## What can operate against real infrastructure

- **Linux WireGuard agent**: authenticated HTTPS health checks plus allowlisted peer create, activate, suspend, and revoke operations. The agent accepts a client public key and IPv4 address only; it never receives a private key or exposes a shell.
- **MikroTik RouterOS**: API/API-SSL connectivity test, resource health, PPP-secret create, suspend, activate, revoke, list, and active session reads. RouterOS credentials are encrypted at rest; PPP passwords are accepted only for provisioning and erased after success.
- **Control plane**: Sanctum bearer sessions, administrator middleware, encrypted server secrets, idempotent queue-backed provisioning, audit logs, plan/expiry records, CSV export without secrets, and a scheduler for expiry and expired-token cleanup.

The interface never marks a host connected until its real driver connection test succeeds. Demo records remain visibly labelled and are for local development only.

## Local development

Requirements: PHP 8.2+ with SQLite, Composer, Node.js 22+.

```powershell
cd backend
php artisan migrate:fresh --seed
php artisan serve
```

In a second terminal:

```powershell
cd frontend
Copy-Item .env.example .env.local
npm run dev
```

Open the Vite address and sign in with `admin@vpn.local` / `password`. Those credentials and demo records are local-only; change/remove them before a shared deployment.

## Container deployment

Requirements: Docker Compose v2. Do not use the local `.env` as a production secret file.

```powershell
Copy-Item .env.docker.example .env.docker
# Edit .env.docker: set distinct long database passwords, then generate APP_KEY:
docker run --rm php:8.3-cli php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"
# Paste the resulting value after APP_KEY= in .env.docker, then start:
docker compose --env-file .env.docker up --build -d
```

The console is served on `http://localhost:8080` by default. Compose runs MySQL, a one-shot migration service, the Laravel API, a queue worker, the scheduler, and an Nginx SPA proxy. For public use, put the `web` service behind HTTPS, set `APP_URL` and `FRONTEND_URL` to the public origin, rotate local demo users, and protect backups/logs.

## Connect infrastructure

1. Follow [agent/README.md](agent/README.md) to set up the WireGuard agent, a real certificate, a 32+ character shared secret, a fixed interface, and its allowed peer network.
2. In **Servers**, register it as **Linux agent** with `wireguard` as the protocol, use the same secret, and click **Test**. The test must report `online` before provisioning.
3. To use RouterOS, create a least-privileged RouterOS API user restricted to the required PPP API paths, expose API-SSL privately, register the server using port `8729`, enable certificate verification with a trusted certificate, and test it before provisioning. Use plain API (`8728`) only inside a trusted private network and with a compensating control.
4. When creating a WireGuard account, generate its key pair on the client and submit only the client public key plus an unused IPv4 address in the configured peer network. VPNOPS does not create or export the client configuration or private key.
5. When creating a RouterOS PPP/L2TP/SSTP/PPTP/OpenVPN account, provide a client password once via the form and deliver it to the client through a secure channel. It is not returned by the API and is cleared after a successful create.

## Verify

```powershell
cd backend; php artisan test
cd ..\frontend; npm run build
python -m py_compile agent\vpn_agent.py
```

For a real acceptance check, add a non-demo test server, use the UI **Test** action, provision one disposable account, confirm the resulting peer/PPP secret directly on the target, suspend it, activate it, revoke it, and inspect the operation log. No server endpoint or credentials are bundled with this repository, so that final infrastructure-specific validation must be performed using your own host.

The public API outline is in [docs/openapi.yaml](docs/openapi.yaml), and the exact protocol limitations are in [docs/protocols.md](docs/protocols.md). RouterOS communication uses the maintained [routeros-api-php client](https://github.com/EvilFreelancer/routeros-api-php).

### Remote Winbox to a customer router

Register the RouterOS server and paste its generated setup script in the CHR terminal. The script creates the dedicated PPP profile, dynamic pool, outbound NAT, and API user. Keep the reserved static remote range separate from every dynamic CHR pool. After **Test** succeeds, use **Edit server** to enable automatic remote access. Enabling checks the actual CHR profile, gateway, pool, and WAN interface; existing servers remain opt-in.

Customers choose the VPN device, remote service, editable destination TCP port, optional LAN target, and the single public IPv4 source allowed to connect (/32). After payment approval, an enabled server reserves a static tunnel IP and public TCP port (20000–59999), excluding application allocations and existing CHR addresses/services/NAT rules. It provisions the PPP account and restricted dst-NAT, src-NAT, and forward rules through API. Account activation and secret erasure happen only after successful provisioning. Retries reuse the same mapping; partial rule failures are cleaned up. Suspending disconnects active PPP sessions and removes automatic remote rules; activation restores them. Revocation/expiry removes managed rules, with cleanup failures visible on account detail.

Servers without automation retain **Akun VPN → Detail → Atur port & generate script CHR**: assign a static tunnel IP and unique public TCP port, generate the source-restricted script, and paste it into CHR. Manual scripts and client-side LAN rules still require manual cleanup.

If CHR is behind another NAT, forward the chosen public port upstream. A client router's input firewall must permit the CHR tunnel gateway. For OLT/CCTV behind a MikroTik client, paste the separate **Script remote LAN** into the client router after its tunnel connects; other gateways require equivalent manual forwarding. CCTV can run the VPN itself only when it supports the selected protocol. A public mapping such as `vpn.example.net:20000` forwards to the client's static tunnel address at `:8291`; the public port and client service port are independent.
