<?php

namespace App\Services\Servers;

use App\Models\VpnServer;
use InvalidArgumentException;

final class DriverFactory
{
    public function for(VpnServer $server): ServerAdapter
    {
        return match ($server->adapter) {
            'demo' => new DemoAdapter,
            'linux-agent' => new LinuxAgentAdapter,
            'linux-openvpn' => new LinuxOpenVpnAdapter,
            'mikrotik-routeros' => new MikroTikAdapter,
            default => throw new InvalidArgumentException("Unknown server adapter [{$server->adapter}]."),
        };
    }
}
