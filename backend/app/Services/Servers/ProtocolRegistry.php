<?php

namespace App\Services\Servers;

final class ProtocolRegistry
{
    public const DEFINITIONS = [
        'wireguard' => ['label' => 'WireGuard', 'model' => 'peer', 'legacy' => false],
        'openvpn' => ['label' => 'OpenVPN', 'model' => 'account', 'legacy' => false],
        'l2tp-ipsec' => ['label' => 'L2TP/IPsec', 'model' => 'account', 'legacy' => false],
        'sstp' => ['label' => 'SSTP', 'model' => 'account', 'legacy' => false],
        'ppp' => ['label' => 'PPP-based VPN', 'model' => 'account', 'legacy' => false],
        'pptp' => ['label' => 'PPTP', 'model' => 'account', 'legacy' => true],
    ];

    public static function supportedBy(array $capabilities): array
    {
        return array_values(array_intersect(array_keys(self::DEFINITIONS), $capabilities['protocols'] ?? []));
    }
}
