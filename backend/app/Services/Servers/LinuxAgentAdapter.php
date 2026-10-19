<?php

namespace App\Services\Servers;

use App\Models\VpnAccount;
use App\Models\VpnServer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final class LinuxAgentAdapter extends AbstractAdapter
{
    public function testConnection(VpnServer $server): array
    {
        $started = hrtime(true);
        try {
            $response = $this->request($server, 'GET', '/v1/health');

            return [
                'supported' => true,
                'status' => $response->successful() ? 'online' : 'degraded',
                'latency_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
                'message' => $response->successful() ? 'Agent health endpoint responded.' : 'Agent returned HTTP '.$response->status().'.',
                'data' => $response->successful() ? $response->json() : null,
            ];
        } catch (ConnectionException $exception) {
            return ['supported' => true, 'status' => 'offline', 'message' => 'Agent was not reachable: '.$exception->getMessage()];
        } catch (\Throwable $exception) {
            return ['supported' => true, 'status' => 'degraded', 'message' => $exception->getMessage()];
        }
    }

    public function getHealth(VpnServer $server): array
    {
        $response = $this->request($server, 'GET', '/v1/health');

        return ['supported' => true, 'status' => $response->successful() ? 'online' : 'degraded', 'data' => $response->json()];
    }

    public function createAccount(VpnServer $server, VpnAccount $account): array
    {
        if ($account->protocol !== 'wireguard') {
            return $this->unsupported('createAccount for '.$account->protocol);
        }
        $response = $this->request($server, 'POST', '/v1/vpn/accounts', $this->wireGuardPayload($account));

        return $this->operationResult($response, $account);
    }

    public function suspendAccount(VpnServer $server, VpnAccount $account): array
    {
        return $this->wireGuardAction($server, $account, 'suspend');
    }

    public function activateAccount(VpnServer $server, VpnAccount $account): array
    {
        return $this->wireGuardAction($server, $account, 'activate');
    }

    public function deleteAccount(VpnServer $server, VpnAccount $account): array
    {
        return $this->wireGuardAction($server, $account, 'revoke');
    }

    public function getSystemMetrics(VpnServer $server): array
    {
        $result = $this->getHealth($server);

        return ['supported' => $result['supported'], 'status' => $result['status'], 'data' => data_get($result, 'data.metrics')];
    }

    private function wireGuardAction(VpnServer $server, VpnAccount $account, string $action): array
    {
        if ($account->protocol !== 'wireguard') {
            return $this->unsupported($action.'Account for '.$account->protocol);
        }
        $response = $this->request($server, 'POST', '/v1/vpn/accounts/actions/'.$action, $this->wireGuardPayload($account));

        return $this->operationResult($response, $account);
    }

    private function wireGuardPayload(VpnAccount $account): array
    {
        $publicKey = data_get($account->adapter_payload, 'wireguard_public_key');
        if (! $publicKey || ! $account->allocated_ip) {
            throw new \RuntimeException('WireGuard account is missing its peer public key or allocated IP.');
        }

        return [
            'idempotency_key' => $account->idempotency_key,
            'protocol' => 'wireguard', 'identifier' => $account->identifier,
            'allocated_ip' => $account->allocated_ip, 'peer_public_key' => $publicKey,
            'expires_at' => optional($account->expires_at)->toIso8601String(),
        ];
    }

    private function operationResult($response, VpnAccount $account): array
    {
        $data = $response->json() ?? [];

        return [
            'supported' => ! in_array($response->status(), [404, 501], true),
            'status' => $response->successful() ? 'success' : 'failed',
            'remote_reference' => $data['remote_reference'] ?? data_get($account->adapter_payload, 'wireguard_public_key'),
            'message' => $data['message'] ?? ('Agent returned HTTP '.$response->status().'.'),
            'data' => $data,
        ];
    }

    private function request(VpnServer $server, string $method, string $path, array $payload = [])
    {
        $credentials = $server->encrypted_credentials ? json_decode(Crypt::decryptString($server->encrypted_credentials), true) : [];
        $secret = $credentials['agent_shared_secret'] ?? null;
        if (! $secret) {
            throw new \RuntimeException('This server has no agent shared secret configured.');
        }

        $timestamp = (string) now()->timestamp;
        $nonce = (string) Str::uuid();
        $body = $payload ? json_encode($payload, JSON_THROW_ON_ERROR) : '';
        $signature = hash_hmac('sha256', implode("\n", [$method, $path, $timestamp, $nonce, $body]), $secret);
        $tunnelPort = (int) ($credentials['agent_port'] ?? 0);
        $usesTunnel = $tunnelPort >= 1024 && $tunnelPort <= 65535;
        // A reverse SSH tunnel terminates on this host and is bound only to
        // loopback. Its transport is already private, while the agent still
        // authenticates every request with HMAC. Direct agents keep their
        // public HTTPS endpoint and configured certificate verification.
        $baseUrl = $usesTunnel
            ? 'https://127.0.0.1:'.$tunnelPort
            : 'https://'.$server->hostname.':'.$server->port;

        return Http::acceptJson()->timeout(5)->connectTimeout(3)
            ->withOptions(['verify' => $usesTunnel ? false : $server->tls_verify])
            ->withHeaders(['X-Agent-Time' => $timestamp, 'X-Agent-Nonce' => $nonce, 'X-Agent-Signature' => $signature])
            ->send($method, $baseUrl.$path, $payload ? ['json' => $payload] : []);
    }
}
