<?php

namespace App\Services\Servers;

use App\Models\VpnAccount;
use App\Models\VpnServer;
use Illuminate\Support\Facades\Crypt;
use RouterOS\Client;
use RouterOS\Exceptions\ConnectException;
use RouterOS\Query;
use Throwable;

/** A deliberately small RouterOS API surface; no generic terminal endpoint. */
final class MikroTikAdapter extends AbstractAdapter
{
    public function testConnection(VpnServer $server): array
    {
        $startedAt = microtime(true);
        try {
            $resource = $this->first($this->client($server)->query(new Query('/system/resource/print'))->read());

            return ['supported' => true, 'status' => 'online', 'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000), 'message' => 'RouterOS API responded successfully.', 'data' => $this->resourceData($resource)];
        } catch (Throwable $exception) {
            return $this->connectionFailure($exception);
        }
    }

    public function getServerInfo(VpnServer $server): array
    {
        try {
            return ['supported' => true, 'status' => 'success', 'data' => $this->resourceData($this->resource($server))];
        } catch (Throwable) {
            return $this->connectionFailure();
        }
    }

    public function getHealth(VpnServer $server): array
    {
        try {
            $resource = $this->resource($server);
            $total = max(1, (int) ($resource['total-memory'] ?? 1));
            $free = max(0, (int) ($resource['free-memory'] ?? 0));

            return ['supported' => true, 'status' => 'online', 'data' => [
                'cpu_percent' => (int) ($resource['cpu-load'] ?? 0),
                'memory_percent' => (int) round((($total - $free) / $total) * 100),
                'uptime' => $resource['uptime'] ?? null,
            ]];
        } catch (Throwable) {
            return $this->connectionFailure();
        }
    }

    public function listAccounts(VpnServer $server): array
    {
        try {
            $accounts = $this->client($server)->query(new Query('/ppp/secret/print'))->read();

            return ['supported' => true, 'status' => 'success', 'data' => array_map(fn (array $row) => [
                'remote_reference' => $row['.id'] ?? null, 'identifier' => $row['name'] ?? null,
                'service' => $row['service'] ?? null, 'profile' => $row['profile'] ?? null,
                'disabled' => ($row['disabled'] ?? 'false') === 'true', 'last_logged_out' => $row['last-logged-out'] ?? null,
            ], $accounts)];
        } catch (Throwable) {
            return $this->connectionFailure();
        }
    }

    public function createAccount(VpnServer $server, VpnAccount $account): array
    {
        $service = $this->pppService($account->protocol);
        if ($service === null) {
            return $this->unsupported('RouterOS provisioning for '.$account->protocol);
        }
        try {
            $password = $account->encrypted_provisioning_secret ? Crypt::decryptString($account->encrypted_provisioning_secret) : null;
            if (! is_string($password) || $password === '') {
                return $this->missingProvisioningSecret();
            }
            $client = $this->client($server);
            $query = (new Query('/ppp/secret/add'))->equal('name', $account->identifier)->equal('password', $password)->equal('service', $service)->equal('disabled', 'no')->equal('comment', $this->comment($account));
            if ($account->allocated_ip) {
                $query->equal('remote-address', $account->allocated_ip);
            }
            $client->query($query)->read();

            return ['supported' => true, 'status' => 'success', 'message' => 'PPP secret created through RouterOS API.', 'remote_reference' => $this->remoteReference($client, $account)];
        } catch (Throwable) {
            return $this->operationFailure();
        }
    }

    public function updateAccount(VpnServer $server, VpnAccount $account): array
    {
        if (! $account->allocated_ip) {
            return $this->unsupported('Updating a RouterOS account without an allocated IP');
        }
        try {
            $client = $this->client($server);
            $remote = $this->remoteReference($client, $account);
            if (! $remote) {
                return $this->notFound();
            }
            $client->query((new Query('/ppp/secret/set'))->equal('.id', $remote)->equal('remote-address', $account->allocated_ip))->read();

            return ['supported' => true, 'status' => 'success', 'remote_reference' => $remote];
        } catch (Throwable) {
            return $this->operationFailure();
        }
    }

    public function suspendAccount(VpnServer $server, VpnAccount $account): array
    {
        return $this->setDisabled($server, $account, true);
    }

    public function activateAccount(VpnServer $server, VpnAccount $account): array
    {
        return $this->setDisabled($server, $account, false);
    }

    public function deleteAccount(VpnServer $server, VpnAccount $account): array
    {
        try {
            $client = $this->client($server);
            $remote = $this->remoteReference($client, $account);
            if (! $remote) {
                return ['supported' => true, 'status' => 'success', 'message' => 'RouterOS PPP secret was already absent.'];
            }
            $client->query((new Query('/ppp/secret/remove'))->equal('.id', $remote))->read();

            return ['supported' => true, 'status' => 'success', 'remote_reference' => $remote];
        } catch (Throwable) {
            return $this->operationFailure();
        }
    }

    public function getActiveSessions(VpnServer $server): array
    {
        try {
            $sessions = $this->client($server)->query(new Query('/ppp/active/print'))->read();

            return ['supported' => true, 'status' => 'success', 'data' => array_map(fn (array $row) => [
                'identifier' => $row['name'] ?? null, 'service' => $row['service'] ?? null,
                'address' => $row['address'] ?? null, 'uptime' => $row['uptime'] ?? null,
            ], $sessions)];
        } catch (Throwable) {
            return $this->connectionFailure();
        }
    }

    public function getTrafficStatistics(VpnServer $server): array
    {
        return $this->unsupported('Per-account RouterOS traffic statistics');
    }

    public function getSystemMetrics(VpnServer $server): array
    {
        return $this->getHealth($server);
    }

    private function client(VpnServer $server): Client
    {
        $credentials = $server->encrypted_credentials ? json_decode(Crypt::decryptString($server->encrypted_credentials), true, 512, JSON_THROW_ON_ERROR) : [];
        if (empty($credentials['routeros_username']) || empty($credentials['routeros_password'])) {
            throw new \RuntimeException('RouterOS credentials are not configured.');
        }
        $useSsl = (bool) ($credentials['routeros_api_ssl'] ?? true);

        return new Client([
            'host' => $server->hostname, 'port' => (int) $server->port,
            'user' => $credentials['routeros_username'], 'pass' => $credentials['routeros_password'], 'ssl' => $useSsl,
            'ssl_options' => ['verify_peer' => (bool) $server->tls_verify, 'verify_peer_name' => (bool) $server->tls_verify, 'allow_self_signed' => false],
            'timeout' => 5, 'socket_timeout' => 8, 'attempts' => 1, 'delay' => 0,
        ]);
    }

    private function resource(VpnServer $server): array
    {
        return $this->first($this->client($server)->query(new Query('/system/resource/print'))->read());
    }

    private function setDisabled(VpnServer $server, VpnAccount $account, bool $disabled): array
    {
        try {
            $client = $this->client($server);
            $remote = $this->remoteReference($client, $account);
            if (! $remote) {
                return $this->notFound();
            }
            $client->query((new Query('/ppp/secret/set'))->equal('.id', $remote)->equal('disabled', $disabled ? 'yes' : 'no'))->read();

            return ['supported' => true, 'status' => 'success', 'remote_reference' => $remote];
        } catch (Throwable) {
            return $this->operationFailure();
        }
    }

    private function remoteReference(Client $client, VpnAccount $account): ?string
    {
        if ($account->remote_reference) {
            return $account->remote_reference;
        }
        $rows = $client->query((new Query('/ppp/secret/print'))->where('comment', $this->comment($account)))->read();

        return $this->first($rows)['.id'] ?? null;
    }

    private function pppService(string $protocol): ?string
    {
        return match ($protocol) {
            'ppp' => 'any', 'l2tp-ipsec' => 'l2tp', 'sstp' => 'sstp', 'pptp' => 'pptp', 'openvpn' => 'ovpn', default => null
        };
    }

    private function comment(VpnAccount $account): string
    {
        return 'vpnops:'.$account->uuid;
    }

    private function first(array $rows): array
    {
        return $rows[0] ?? [];
    }

    private function resourceData(array $row): array
    {
        return ['identity' => $row['board-name'] ?? null, 'version' => $row['version'] ?? null, 'uptime' => $row['uptime'] ?? null, 'cpu_load' => $row['cpu-load'] ?? null];
    }

    private function connectionFailure(?Throwable $exception = null): array
    {
        $message = $exception instanceof ConnectException
            ? 'Could not open a TCP session to the RouterOS API. Check the published host and port, RouterOS /ip service address restriction, and input firewall.'
            : 'RouterOS API connection failed. Check the server settings and operation log.';

        return ['supported' => true, 'status' => 'offline', 'message' => $message];
    }

    private function operationFailure(): array
    {
        return ['supported' => true, 'status' => 'failed', 'message' => 'RouterOS API operation failed. Check the operation log.'];
    }

    private function missingProvisioningSecret(): array
    {
        return ['supported' => false, 'status' => 'failed', 'message' => 'The one-time client password is unavailable. Re-create this account with a new password.'];
    }

    private function notFound(): array
    {
        return ['supported' => false, 'status' => 'not_found', 'message' => 'The managed RouterOS PPP secret was not found.'];
    }
}
