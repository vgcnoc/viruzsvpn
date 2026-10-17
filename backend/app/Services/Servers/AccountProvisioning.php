<?php

namespace App\Services\Servers;

use App\Models\VpnServer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Throwable;

final class AccountProvisioning
{
    public function __construct(private readonly MikroTikAdapter $routerOs) {}

    public function availableProtocols(VpnServer $server): array
    {
        $advertised = ProtocolRegistry::supportedBy($server->capabilities ?? []);

        if ($server->adapter === 'demo') {
            return $advertised;
        }

        if ($server->status !== 'online') {
            return [];
        }

        if ($server->adapter === 'linux-agent') {
            return array_values(array_intersect($advertised, ['wireguard']));
        }

        if ($server->adapter !== 'mikrotik-routeros') {
            return [];
        }

        $ready = Cache::remember('virvpn:provisionable-protocols:'.$server->id, 30,
            fn () => $this->routerOs->sellableProtocols($server));

        try {
            $credentials = $server->encrypted_credentials
                ? json_decode(Crypt::decryptString($server->encrypted_credentials), true, 512, JSON_THROW_ON_ERROR)
                : [];
        } catch (Throwable) {
            return [];
        }

        if (empty($credentials['routeros_l2tp_secret'])) {
            $ready = array_values(array_diff($ready, ['l2tp-ipsec']));
        }

        // RouterOS WireGuard peers are not provisioned by MikroTikAdapter yet.
        // PPP is a generic RouterOS service, not a customer connection protocol.
        return array_values(array_intersect($advertised, $ready, ['sstp', 'l2tp-ipsec', 'openvpn', 'pptp']));
    }
}
