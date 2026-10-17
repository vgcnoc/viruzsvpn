<?php

namespace App\Services\Servers;

use App\Models\VpnAccount;
use App\Models\VpnServer;

abstract class AbstractAdapter implements ServerAdapter
{
    protected function unsupported(string $operation): array
    {
        return ['supported' => false, 'status' => 'unsupported', 'message' => "$operation is not supported by this adapter."];
    }

    public function getServerInfo(VpnServer $server): array
    {
        return $this->unsupported('getServerInfo');
    }

    public function getHealth(VpnServer $server): array
    {
        return $this->unsupported('getHealth');
    }

    public function listAccounts(VpnServer $server): array
    {
        return $this->unsupported('listAccounts');
    }

    public function createAccount(VpnServer $server, VpnAccount $account): array
    {
        return $this->unsupported('createAccount');
    }

    public function updateAccount(VpnServer $server, VpnAccount $account): array
    {
        return $this->unsupported('updateAccount');
    }

    public function suspendAccount(VpnServer $server, VpnAccount $account): array
    {
        return $this->unsupported('suspendAccount');
    }

    public function activateAccount(VpnServer $server, VpnAccount $account): array
    {
        return $this->unsupported('activateAccount');
    }

    public function deleteAccount(VpnServer $server, VpnAccount $account): array
    {
        return $this->unsupported('deleteAccount');
    }

    public function getActiveSessions(VpnServer $server): array
    {
        return $this->unsupported('getActiveSessions');
    }

    public function getTrafficStatistics(VpnServer $server): array
    {
        return $this->unsupported('getTrafficStatistics');
    }

    public function getSystemMetrics(VpnServer $server): array
    {
        return $this->unsupported('getSystemMetrics');
    }
}
