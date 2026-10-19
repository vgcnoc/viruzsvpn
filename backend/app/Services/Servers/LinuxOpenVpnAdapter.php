<?php

namespace App\Services\Servers;

use App\Models\VpnAccount;
use App\Models\VpnServer;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** A local HMAC-authenticated agent manages one dedicated OpenVPN instance. */
final class LinuxOpenVpnAdapter extends AbstractAdapter
{
    public function testConnection(VpnServer $server): array
    {
        $started = hrtime(true);
        try {
            $response = $this->request($server, 'GET', '/v1/health');
            $online = $response->successful() && $response->json('vpn_services.openvpn.status') === 'online'
                && (int) $response->json('vpn_services.openvpn.port') === (int) $server->port;
            return ['supported' => true, 'status' => $online ? 'online' : 'degraded', 'vpn_status' => $online ? 'online' : 'unknown',
                'latency_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
                'message' => $online ? 'Linux OpenVPN agent and management socket are ready.' : 'Linux OpenVPN agent is not ready.',
                'data' => $response->successful() ? $response->json() : null];
        } catch (\Throwable) {
            return ['supported' => true, 'status' => 'offline', 'message' => 'The local OpenVPN agent could not be reached.'];
        }
    }

    public function getHealth(VpnServer $server): array
    {
        return $this->testConnection($server);
    }

    public function getSystemMetrics(VpnServer $server): array
    {
        $result = $this->getHealth($server);

        return ['supported' => true, 'status' => $result['status'], 'data' => data_get($result, 'data.metrics')];
    }

    public function createAccount(VpnServer $server, VpnAccount $account): array
    {
        if ($account->protocol !== 'openvpn' || ! $account->encrypted_provisioning_secret) {
            return $this->unsupported('OpenVPN provisioning without a one-time password');
        }
        $payload = $this->identity($account) + [
            'idempotency_key' => $account->idempotency_key, 'password' => Crypt::decryptString($account->encrypted_provisioning_secret),
            'allocated_ip' => $account->allocated_ip, 'remote_target_port' => $account->remote_target_port,
            'remote_allowed_cidr' => $account->remote_allowed_cidr,
        ];
        return $this->operation($server, '/v1/vpn/accounts', $payload);
    }

    public function suspendAccount(VpnServer $server, VpnAccount $account): array
    {
        return $this->action($server, $account, 'suspend');
    }

    public function activateAccount(VpnServer $server, VpnAccount $account): array
    {
        return $this->action($server, $account, 'activate');
    }

    public function deleteAccount(VpnServer $server, VpnAccount $account): array
    {
        return $this->action($server, $account, 'revoke');
    }

    public function getActiveSessions(VpnServer $server): array
    {
        try {
            $response = $this->request($server, 'GET', '/v1/sessions');
            return ['supported' => true, 'status' => $response->successful() ? 'success' : 'failed', 'data' => $response->json('sessions') ?? []];
        } catch (\Throwable) {
            return ['supported' => true, 'status' => 'offline', 'message' => 'OpenVPN sessions are unavailable.'];
        }
    }

    public function disconnectSession(VpnServer $server, VpnAccount $account, string $sessionId): array
    {
        if (! preg_match('/^ovpn:[0-9]{1,12}$/', $sessionId)) {
            return $this->unsupported('Invalid OpenVPN session');
        }
        return $this->operation($server, '/v1/sessions/disconnect', $this->identity($account) + ['session_id' => $sessionId]);
    }

    public function clientConfiguration(VpnServer $server, VpnAccount $account): string
    {
        $response = $this->request($server, 'GET', '/v1/client-template');
        if (! $response->successful()) {
            throw new \RuntimeException('OpenVPN client template is unavailable.');
        }
        $ca = trim((string) $response->json('ca'));
        $name = (string) $response->json('cert_name');
        if (! preg_match('/^-----BEGIN CERTIFICATE-----[A-Za-z0-9+\/=\r\n]+-----END CERTIFICATE-----$/', $ca)
            || ! preg_match('/^[A-Za-z0-9._-]+$/', $name)
            || ! preg_match('/^[A-Za-z0-9.:-]+$/', $server->hostname)) {
            throw new \RuntimeException('OpenVPN client template is invalid.');
        }
        return "# virVPN account: {$account->identifier}\nclient\ndev tun\nproto tcp-client\nremote {$server->hostname} {$server->port}\nresolv-retry infinite\nnobind\npersist-key\npersist-tun\nremote-cert-tls server\nverify-x509-name {$name} name\nauth-user-pass\nauth-nocache\ncipher AES-256-CBC\ndata-ciphers AES-256-GCM:AES-256-CBC\nauth SHA1\ntls-version-min 1.2\nverb 3\n<ca>\n{$ca}\n</ca>\n";
    }

    private function action(VpnServer $server, VpnAccount $account, string $action): array
    {
        if ($account->protocol !== 'openvpn') {
            return $this->unsupported($action.' for '.$account->protocol);
        }
        $result = $this->operation($server, '/v1/vpn/accounts/actions/'.$action, $this->identity($account));
        if ($result['status'] === 'success' && $account->remote_public_port) {
            $account->update(['remote_rule_status' => match ($action) {'activate' => 'active', 'suspend' => 'suspended', default => 'removed'}]);
        }
        return $result;
    }

    private function identity(VpnAccount $account): array
    {
        return ['protocol' => $account->protocol, 'account_uuid' => $account->uuid, 'identifier' => $account->identifier];
    }

    private function operation(VpnServer $server, string $path, array $payload): array
    {
        try {
            $response = $this->request($server, 'POST', $path, $payload);
            return ['supported' => true, 'status' => $response->successful() ? 'success' : 'failed',
                'remote_reference' => $response->json('remote_reference'),
                'message' => $response->successful() ? 'Linux OpenVPN operation completed.' : ($response->json('message') ?? 'Linux OpenVPN operation failed.'),
                'data' => $response->successful() ? ($response->json('data') ?? []) : []];
        } catch (\Throwable) {
            return ['supported' => true, 'status' => 'failed', 'message' => 'The Linux OpenVPN agent could not complete this operation.'];
        }
    }

    private function request(VpnServer $server, string $method, string $path, array $payload = [])
    {
        $credentials = $server->encrypted_credentials ? json_decode(Crypt::decryptString($server->encrypted_credentials), true, 512, JSON_THROW_ON_ERROR) : [];
        $secret = $credentials['agent_shared_secret'] ?? '';
        $port = (int) ($credentials['agent_port'] ?? 9444);
        if (strlen($secret) < 32 || $port < 1024 || $port > 65535) {
            throw new \RuntimeException('Local OpenVPN agent credentials are incomplete.');
        }
        $timestamp = (string) now()->timestamp;
        $nonce = (string) Str::uuid();
        $body = $payload ? json_encode($payload, JSON_THROW_ON_ERROR) : '';
        $signature = hash_hmac('sha256', implode("\n", [$method, $path, $timestamp, $nonce, $body]), $secret);
        $request = Http::acceptJson()->timeout(40)->connectTimeout(3)
            ->withHeaders(['Content-Type' => 'application/json', 'X-Agent-Time' => $timestamp, 'X-Agent-Nonce' => $nonce, 'X-Agent-Signature' => $signature]);
        return $request->send($method, 'http://127.0.0.1:'.$port.$path, $payload ? ['body' => $body] : []);
    }
}
