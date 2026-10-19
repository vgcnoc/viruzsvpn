<?php

namespace App\Services\Servers;

use App\Models\VpnAccount;
use App\Models\VpnServer;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Reserves a dedicated tunnel address and public TCP port before PPP provisioning. */
final class AutomaticRemoteAccess
{
    public function enabledFor(VpnAccount $account): bool
    {
        return $account->server->adapter === 'mikrotik-routeros'
            && $account->remote_target_port && $account->remote_allowed_cidr
            && (bool) ($this->settings($account->server)['routeros_remote_auto_enabled'] ?? false);
    }

    public function prepare(VpnAccount $account, array $blockedPorts = [], array $blockedIps = []): void
    {
        if (! $this->enabledFor($account)) {
            return;
        }

        DB::transaction(function () use ($account, $blockedPorts, $blockedIps) {
            // Serialize reservations on this server, including separate queue workers.
            VpnServer::whereKey($account->vpn_server_id)->lockForUpdate()->firstOrFail();
            $fresh = VpnAccount::whereKey($account->id)->lockForUpdate()->firstOrFail();
            $settings = $this->settings($account->server);
            [$start, $end] = Ipv4Range::parse($settings['routeros_remote_ip_pool']);
            $usedIps = VpnAccount::where('vpn_server_id', $account->vpn_server_id)->whereKeyNot($account->id)
                ->whereNotNull('allocated_ip')->pluck('allocated_ip')->flip();
            $usedPorts = VpnAccount::where('vpn_server_id', $account->vpn_server_id)->whereKeyNot($account->id)
                ->whereNotNull('remote_public_port')->pluck('remote_public_port')->flip();

            $ip = $fresh->allocated_ip;
            if ($ip && (ip2long($ip) < $start || ip2long($ip) > $end || isset($blockedIps[$ip]))) {
                throw new RuntimeException('The reserved tunnel address is outside the remote range or is already used on the CHR.');
            }
            if (! $ip) {
                for ($value = $start; $value <= $end; $value++) {
                    $candidate = long2ip($value);
                    if ($candidate !== $settings['routeros_vpn_gateway'] && ! isset($usedIps[$candidate])
                        && ! isset($blockedIps[$candidate])) {
                        $ip = $candidate;
                        break;
                    }
                }
            }
            if (! $ip || isset($usedIps[$ip])) {
                throw new RuntimeException('No unique static VPN address is available in the dedicated pool.');
            }

            $port = $fresh->remote_public_port;
            if (! $port) {
                for ($candidate = 20000; $candidate <= 59999; $candidate++) {
                    if ($candidate !== (int) $account->server->port && ! isset($usedPorts[$candidate])
                        && ! isset($blockedPorts[$candidate])) {
                        $port = $candidate;
                        break;
                    }
                }
            }
            if (! $port || isset($usedPorts[$port])) {
                throw new RuntimeException('No unique public remote port is available.');
            }

            $fresh->update([
                'allocated_ip' => $ip, 'remote_public_port' => $port,
                'remote_wan_interface' => $settings['routeros_wan_interface'],
                'remote_gateway_ip' => $settings['routeros_vpn_gateway'],
                'remote_rule_status' => 'pending',
            ]);
            $account->refresh();
        });
    }

    private function settings(VpnServer $server): array
    {
        return $server->encrypted_credentials
            ? json_decode(Crypt::decryptString($server->encrypted_credentials), true, 512, JSON_THROW_ON_ERROR) : [];
    }
}
