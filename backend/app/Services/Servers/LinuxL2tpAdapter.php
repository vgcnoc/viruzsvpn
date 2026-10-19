<?php

namespace App\Services\Servers;

use App\Models\VpnAccount;
use App\Models\VpnServer;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** HMAC-authenticated local agent for a virVPN-managed L2TP/IPsec endpoint. */
final class LinuxL2tpAdapter extends AbstractAdapter
{
    public function testConnection(VpnServer $server): array
    {
        $started = hrtime(true);
        try {
            $response = $this->request($server, 'GET', '/v1/health');
            $online = $response->successful() && $response->json('vpn_services.l2tp-ipsec.status') === 'online';

            return ['supported' => true, 'status' => $online ? 'online' : 'degraded', 'vpn_status' => $online ? 'online' : 'unknown',
                'latency_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
                'message' => $online ? 'Linux L2TP/IPsec agent and services are ready.' : 'Linux L2TP/IPsec service is not ready.',
                'data' => $response->successful() ? $response->json() : null];
        } catch (\Throwable) {
            return ['supported' => true, 'status' => 'offline', 'message' => 'The local L2TP/IPsec agent could not be reached.'];
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
        if ($account->protocol !== 'l2tp-ipsec' || ! $account->encrypted_provisioning_secret) {
            return $this->unsupported('L2TP/IPsec provisioning without a one-time password');
        }

        return $this->operation($server, '/v1/vpn/accounts', $this->identity($account) + [
            'idempotency_key' => $account->idempotency_key,
            'password' => Crypt::decryptString($account->encrypted_provisioning_secret),
        ]);
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

    private function action(VpnServer $server, VpnAccount $account, string $action): array
    {
        if ($account->protocol !== 'l2tp-ipsec') {
            return $this->unsupported($action.' for '.$account->protocol);
        }

        return $this->operation($server, '/v1/vpn/accounts/actions/'.$action, $this->identity($account));
    }

    private function identity(VpnAccount $account): array
    {
        return ['protocol' => 'l2tp-ipsec', 'account_uuid' => $account->uuid, 'identifier' => $account->identifier];
    }

    private function operation(VpnServer $server, string $path, array $payload): array
    {
        try {
            $response = $this->request($server, 'POST', $path, $payload);

            return ['supported' => true, 'status' => $response->successful() ? 'success' : 'failed',
                'remote_reference' => $response->json('remote_reference'),
                'message' => $response->successful() ? 'Linux L2TP/IPsec operation completed.' : ($response->json('message') ?? 'Linux L2TP/IPsec operation failed.'),
                'data' => $response->successful() ? ($response->json('data') ?? []) : []];
        } catch (\Throwable) {
            return ['supported' => true, 'status' => 'failed', 'message' => 'The Linux L2TP/IPsec agent could not complete this operation.'];
        }
    }

    private function request(VpnServer $server, string $method, string $path, array $payload = [])
    {
        $credentials = $server->encrypted_credentials ? json_decode(Crypt::decryptString($server->encrypted_credentials), true, 512, JSON_THROW_ON_ERROR) : [];
        $secret = $credentials['agent_shared_secret'] ?? '';
        $port = (int) ($credentials['agent_port'] ?? 9447);
        if (strlen($secret) < 32 || $port < 1024 || $port > 65535) {
            throw new \RuntimeException('Local L2TP/IPsec agent credentials are incomplete.');
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
