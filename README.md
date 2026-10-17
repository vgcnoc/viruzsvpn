# VPNOPS — VPN management platform

VPNOPS is a production-oriented control plane for real VPN infrastructure. It is a fresh project and does not reuse VPNRENT. The React console calls a Laravel API that uses a small driver boundary for every server action; no browser can run an arbitrary server command.

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
