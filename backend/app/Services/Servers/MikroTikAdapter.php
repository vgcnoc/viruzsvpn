<?php

namespace App\Services\Servers;

use App\Models\VpnAccount;
use App\Models\VpnServer;
use Illuminate\Support\Facades\Crypt;
use RouterOS\Client;
use RouterOS\Exceptions\ConnectException;
use RouterOS\Query;
use RouterOS\Interfaces\ClientInterface;
use Throwable;

/** A deliberately small RouterOS API surface; no generic terminal endpoint. */
final class MikroTikAdapter extends AbstractAdapter
{
    public function __construct(private readonly ?ClientInterface $connection = null) {}

    public function sellableProtocols(VpnServer $server): array
    {
        try {
            $client = $this->client($server);
            $services = [
                'l2tp-ipsec' => '/interface/l2tp-server/server/print',
                'sstp' => '/interface/sstp-server/server/print',
                'openvpn' => '/interface/ovpn-server/server/print',
                'pptp' => '/interface/pptp-server/server/print',
            ];
            $ready = [];
            foreach ($services as $protocol => $path) {
                try {
                    $row = $this->first($client->query(new Query($path))->read());
                } catch (Throwable) {
                    continue;
                }
                $enabled = in_array(strtolower((string) ($row['enabled'] ?? '')), ['true', 'yes'], true)
                    || in_array(strtolower((string) ($row['disabled'] ?? '')), ['false', 'no'], true);
                if (! $row || ! $enabled) {
                    continue;
                }
                if ($protocol === 'l2tp-ipsec' && ($row['use-ipsec'] ?? '') !== 'required') {
                    continue;
                }
                if ($protocol === 'sstp' && (empty($row['certificate']) || $row['certificate'] === 'none')) {
                    continue;
                }
                $ready[] = $protocol;
            }

            return $ready;
        } catch (Throwable) {
            return [];
        }
    }

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
            $credentials = $server->encrypted_credentials ? json_decode(Crypt::decryptString($server->encrypted_credentials), true, 512, JSON_THROW_ON_ERROR) : [];
            if (! is_string($password) || $password === '') {
                return $this->missingProvisioningSecret();
            }
            $client = $this->client($server);
            $existing = $this->remoteReference($client, $account);
            if ($existing) {
                return ['supported' => true, 'status' => 'success', 'message' => 'RouterOS PPP secret already exists.', 'remote_reference' => $existing];
            }
            $query = (new Query('/ppp/secret/add'))->equal('name', $account->identifier)->equal('password', $password)->equal('service', $service)->equal('disabled', 'no')->equal('comment', $this->comment($account));
            if (! empty($credentials['routeros_ppp_profile'])) {
                $query->equal('profile', $credentials['routeros_ppp_profile']);
            }
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

    /** Install only rules owned by this account; never modify an unrelated firewall rule. */
    public function syncRemoteAccess(VpnServer $server, VpnAccount $account, bool $cleanupOnFailure = true): array
    {
        if (! $account->allocated_ip || ! $account->remote_public_port || ! $account->remote_target_port
            || ! $account->remote_allowed_cidr || ! $account->remote_wan_interface || ! $account->remote_gateway_ip) {
            return ['status' => 'failed', 'message' => 'Remote access settings are incomplete.'];
        }
        try {
            $client = $this->client($server);
            $base = 'virVPN remote account '.$account->id;
            $dstComment = $base.' dstnat';
            if (isset($this->reservedTcpServicePorts($client)[$account->remote_public_port])) {
                return ['status' => 'failed', 'message' => 'The chosen public TCP port is reserved for a CHR service.'];
            }
            $nat = $client->query(new Query('/ip/firewall/nat/print'))->read();
            foreach ($nat as $rule) {
                if (($rule['chain'] ?? '') !== 'dstnat' || ($rule['protocol'] ?? '') !== 'tcp'
                    || ($rule['comment'] ?? '') === $dstComment) {
                    continue;
                }
                if ($this->portMatches((string) ($rule['dst-port'] ?? ''), $account->remote_public_port)) {
                    return ['status' => 'failed', 'message' => 'The chosen public TCP port already has a CHR dst-NAT rule.'];
                }
            }

            $this->upsertFirewallRule($client, 'nat', $dstComment, [
                'chain' => 'dstnat', 'action' => 'dst-nat', 'in-interface' => $account->remote_wan_interface,
                'protocol' => 'tcp', 'src-address' => $account->remote_allowed_cidr,
                'dst-port' => (string) $account->remote_public_port,
                'to-addresses' => $account->allocated_ip, 'to-ports' => (string) $account->remote_target_port,
            ]);
            $this->upsertFirewallRule($client, 'nat', $base.' srcnat', [
                'chain' => 'srcnat', 'action' => 'src-nat', 'protocol' => 'tcp',
                'src-address' => $account->remote_allowed_cidr, 'dst-address' => $account->allocated_ip,
                'dst-port' => (string) $account->remote_target_port, 'to-addresses' => $account->remote_gateway_ip,
            ]);
            $this->upsertFirewallRule($client, 'filter', $base.' forward', [
                'chain' => 'forward', 'action' => 'accept', 'in-interface' => $account->remote_wan_interface,
                'protocol' => 'tcp', 'src-address' => $account->remote_allowed_cidr,
                'dst-address' => $account->allocated_ip, 'dst-port' => (string) $account->remote_target_port,
            ]);

            return ['status' => 'success', 'message' => 'Restricted CHR remote rules installed.'];
        } catch (Throwable $exception) {
            report($exception);
            if (! $cleanupOnFailure) {
                return ['status' => 'failed', 'message' => 'Could not update CHR remote rules; the prior mapping must be verified.'];
            }
            $cleanup = $this->removeRemoteAccess($server, $account);
            return ['status' => 'failed', 'message' => $cleanup['status'] === 'success'
                ? 'Could not install CHR remote rules; partial rules were removed.'
                : 'Could not install CHR remote rules or clean up partial rules. Check the router firewall urgently.'];
        }
    }

    public function remotePrerequisites(VpnServer $server): array
    {
        try {
            $settings = $server->encrypted_credentials
                ? json_decode(Crypt::decryptString($server->encrypted_credentials), true, 512, JSON_THROW_ON_ERROR) : [];
            $client = $this->client($server);
            $profile = $this->first($client->query((new Query('/ppp/profile/print'))
                ->where('name', $settings['routeros_ppp_profile'] ?? 'virvpn-standard'))->read());
            $pools = $client->query(new Query('/ip/pool/print'))->read();
            $pool = collect($pools)->firstWhere('name', 'virvpn-pool');
            $wan = $this->first($client->query((new Query('/interface/print'))
                ->where('name', $settings['routeros_wan_interface'] ?? ''))->read());
            if (! $profile || ($profile['local-address'] ?? '') !== ($settings['routeros_vpn_gateway'] ?? '')
                || ! $pool || ($pool['ranges'] ?? '') !== ($settings['routeros_vpn_pool'] ?? '') || ! $wan) {
                return ['status' => 'failed', 'message' => 'CHR remote prerequisites do not match the saved PPP profile, pool, gateway, or WAN interface. Paste and verify the server setup script first.'];
            }
            if (empty($settings['routeros_remote_ip_pool'])) {
                return ['status' => 'failed', 'message' => 'A separate static remote IP range is required.'];
            }
            foreach ($pools as $candidate) {
                foreach (explode(',', $candidate['ranges'] ?? '') as $range) {
                    if (Ipv4Range::overlaps($settings['routeros_remote_ip_pool'], trim($range))) {
                        return ['status' => 'failed', 'message' => 'The reserved static remote range overlaps an existing CHR IP pool. Choose an unused range before enabling automation.'];
                    }
                }
            }
            return ['status' => 'success'];
        } catch (Throwable $exception) {
            report($exception);
            return ['status' => 'failed', 'message' => 'Could not verify CHR remote prerequisites.'];
        }
    }

    public function usedPublicTcpPorts(VpnServer $server): array
    {
        try {
            $client = $this->client($server);
            $ports = $this->reservedTcpServicePorts($client);
            foreach ($client->query(new Query('/ip/firewall/nat/print'))->read() as $rule) {
                if (($rule['chain'] ?? '') !== 'dstnat' || ($rule['protocol'] ?? '') !== 'tcp') {
                    continue;
                }
                foreach (explode(',', (string) ($rule['dst-port'] ?? '')) as $part) {
                    if (preg_match('/^(\d+)-(\d+)$/', $part, $range)) {
                        for ($port = max(20000, (int) $range[1]); $port <= min(59999, (int) $range[2]); $port++) {
                            $ports[$port] = true;
                        }
                    } elseif (ctype_digit($part)) {
                        $ports[(int) $part] = true;
                    }
                }
            }
            return ['status' => 'success', 'ports' => array_keys($ports)];
        } catch (Throwable $exception) {
            report($exception);
            return ['status' => 'failed', 'message' => 'Could not inspect existing public TCP ports on the CHR.'];
        }
    }

    public function usedTunnelIps(VpnServer $server, VpnAccount $account): array
    {
        try {
            $client = $this->client($server);
            $addresses = [];
            foreach ($client->query(new Query('/ppp/secret/print'))->read() as $secret) {
                if (($secret['comment'] ?? '') !== $this->comment($account)
                    && filter_var($secret['remote-address'] ?? '', FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $addresses[$secret['remote-address']] = true;
                }
            }
            foreach ($client->query(new Query('/ppp/active/print'))->read() as $session) {
                if (($session['name'] ?? '') !== $account->identifier
                    && filter_var($session['address'] ?? '', FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $addresses[$session['address']] = true;
                }
            }
            foreach ($client->query(new Query('/ip/address/print'))->read() as $address) {
                $ip = explode('/', $address['address'] ?? '')[0];
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $addresses[$ip] = true;
                }
            }
            return ['status' => 'success', 'addresses' => $addresses];
        } catch (Throwable $exception) {
            report($exception);
            return ['status' => 'failed', 'message' => 'Could not inspect existing tunnel addresses on the CHR.'];
        }
    }

    public function removeRemoteAccess(VpnServer $server, VpnAccount $account): array
    {
        try {
            $client = $this->client($server);
            $base = 'virVPN remote account '.$account->id;
            foreach (['dstnat' => 'nat', 'srcnat' => 'nat', 'forward' => 'filter'] as $suffix => $section) {
                $path = '/ip/firewall/'.$section;
                $rules = $client->query((new Query($path.'/print'))->where('comment', $base.' '.$suffix))->read();
                foreach ($rules as $rule) {
                    $client->query((new Query($path.'/remove'))->equal('.id', $rule['.id']))->read();
                }
            }
            return ['status' => 'success'];
        } catch (Throwable $exception) {
            report($exception);
            return ['status' => 'failed', 'message' => 'Could not remove managed CHR remote rules.'];
        }
    }

    private function upsertFirewallRule(ClientInterface $client, string $section, string $comment, array $properties): void
    {
        $path = '/ip/firewall/'.$section;
        $existing = $this->first($client->query((new Query($path.'/print'))->where('comment', $comment))->read());
        $command = new Query($path.($existing ? '/set' : '/add'));
        if ($existing) {
            $command->equal('.id', $existing['.id']);
        }
        foreach ($properties as $key => $value) {
            $command->equal($key, $value);
        }
        $command->equal('comment', $comment);
        $client->query($command)->read();
        $rule = $this->first($client->query((new Query($path.'/print'))->where('comment', $comment))->read());
        if (! isset($rule['.id'])) {
            throw new \RuntimeException('RouterOS did not return the new firewall rule.');
        }
        $client->query((new Query($path.'/move'))->equal('numbers', $rule['.id'])->equal('destination', '0'))->read();
    }

    private function portMatches(string $rulePorts, int $port): bool
    {
        foreach (explode(',', $rulePorts) as $part) {
            if (preg_match('/^(\d+)-(\d+)$/', $part, $range)) {
                if ($port >= (int) $range[1] && $port <= (int) $range[2]) {
                    return true;
                }
            } elseif (ctype_digit($part) && (int) $part === $port) {
                return true;
            }
        }
        return false;
    }

    private function reservedTcpServicePorts(ClientInterface $client): array
    {
        $ports = [];
        foreach ($client->query(new Query('/ip/service/print'))->read() as $service) {
            $ports[(int) ($service['port'] ?? 0)] = true;
        }
        foreach (['sstp' => 443, 'ovpn' => 1194] as $service => $defaultPort) {
            foreach ($client->query(new Query('/interface/'.$service.'-server/server/print'))->read() as $row) {
                $ports[(int) ($row['port'] ?? $defaultPort)] = true;
            }
        }
        return $ports;
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
            $client->query((new Query('/ppp/secret/set'))->equal('.id', $remote)->equal('disabled', 'yes'))->read();
            $this->disconnectManagedSessions($client, $account);
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
                'id' => $row['.id'] ?? null, 'identifier' => $row['name'] ?? null,
                'service' => $row['service'] ?? null, 'address' => $row['address'] ?? null,
                'caller_id' => $row['caller-id'] ?? null, 'uptime' => $row['uptime'] ?? null,
            ], $sessions)];
        } catch (Throwable) {
            return $this->connectionFailure();
        }
    }

    public function disconnectSession(VpnServer $server, VpnAccount $account, string $sessionId): array
    {
        $service = $this->pppService($account->protocol);
        if ($service === null) {
            return $this->unsupported('Disconnecting this VPN protocol');
        }
        try {
            $client = $this->client($server);
            $sessions = $client->query(new Query('/ppp/active/print'))->read();
            $session = collect($sessions)->first(fn (array $row) =>
                ($row['.id'] ?? null) === $sessionId
                && ($row['name'] ?? null) === $account->identifier
                && ($service === 'any' || ($row['service'] ?? null) === $service)
            );
            if (! $session) {
                return ['supported' => true, 'status' => 'not_found', 'message' => 'Sesi akun ini sudah berakhir atau tidak cocok. Muat ulang monitoring.'];
            }
            $client->query((new Query('/ppp/active/remove'))->equal('.id', $sessionId))->read();

            return ['supported' => true, 'status' => 'success', 'message' => 'Sesi RouterOS diputus. Akun tetap aktif.'];
        } catch (Throwable) {
            return $this->operationFailure();
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

    private function client(VpnServer $server): ClientInterface
    {
        if ($this->connection) {
            return $this->connection;
        }
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
            if (! $disabled && in_array($account->remote_rule_status, ['suspended', 'cleanup_failed'], true)) {
                $result = $this->syncRemoteAccess($server, $account, false);
                if (($result['status'] ?? '') !== 'success') {
                    return ['supported' => true] + $result;
                }
            }
            $client->query((new Query('/ppp/secret/set'))->equal('.id', $remote)->equal('disabled', $disabled ? 'yes' : 'no'))->read();
            if ($disabled) {
                $this->disconnectManagedSessions($client, $account);
                if ($account->remote_rule_status === 'active') {
                    $cleanup = $this->removeRemoteAccess($server, $account);
                    $account->update(['remote_rule_status' => $cleanup['status'] === 'success' ? 'suspended' : 'cleanup_failed']);
                }
            } elseif (in_array($account->remote_rule_status, ['suspended', 'cleanup_failed'], true)) {
                $account->update(['remote_rule_status' => 'active']);
            }

            return ['supported' => true, 'status' => 'success', 'remote_reference' => $remote];
        } catch (Throwable) {
            return $this->operationFailure();
        }
    }

    private function disconnectManagedSessions(ClientInterface $client, VpnAccount $account): void
    {
        foreach ($client->query((new Query('/ppp/active/print'))->where('name', $account->identifier))->read() as $session) {
            if (isset($session['.id'])) {
                $client->query((new Query('/ppp/active/remove'))->equal('.id', $session['.id']))->read();
            }
        }
    }

    private function remoteReference(ClientInterface $client, VpnAccount $account): ?string
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
