<?php

namespace App\Services\Servers;

use App\Models\VpnAccount;
use App\Models\VpnServer;

final class DemoAdapter extends AbstractAdapter
{
    public function testConnection(VpnServer $server): array
    {
        return ['supported' => true, 'status' => 'demo', 'latency_ms' => 0, 'message' => 'Demo server: no network connection was attempted.'];
    }

    public function getHealth(VpnServer $server): array
    {
        return ['supported' => true, 'status' => 'demo', 'message' => 'Demo-only health data.'];
    }

    public function createAccount(VpnServer $server, VpnAccount $account): array
    {
        return ['supported' => true, 'status' => 'demo', 'remote_reference' => 'demo-'.$account->uuid, 'message' => 'Demo account provisioned locally; no VPN server was changed.'];
    }

    public function suspendAccount(VpnServer $server, VpnAccount $account): array
    {
        return ['supported' => true, 'status' => 'demo'];
    }

    public function activateAccount(VpnServer $server, VpnAccount $account): array
    {
        return ['supported' => true, 'status' => 'demo'];
    }

    public function deleteAccount(VpnServer $server, VpnAccount $account): array
    {
        return ['supported' => true, 'status' => 'demo'];
    }
}
