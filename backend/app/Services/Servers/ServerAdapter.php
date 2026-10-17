<?php

namespace App\Services\Servers;

use App\Models\VpnAccount;
use App\Models\VpnServer;

/**
 * A driver boundary: controllers never talk to RouterOS, agents, or a shell.
 * Unsupported operations must return { supported: false } rather than pretending success.
 */
interface ServerAdapter
{
    public function testConnection(VpnServer $server): array;

    public function getServerInfo(VpnServer $server): array;

    public function getHealth(VpnServer $server): array;

    public function listAccounts(VpnServer $server): array;

    public function createAccount(VpnServer $server, VpnAccount $account): array;

    public function updateAccount(VpnServer $server, VpnAccount $account): array;

    public function suspendAccount(VpnServer $server, VpnAccount $account): array;

    public function activateAccount(VpnServer $server, VpnAccount $account): array;

    public function deleteAccount(VpnServer $server, VpnAccount $account): array;

    public function getActiveSessions(VpnServer $server): array;

    public function getTrafficStatistics(VpnServer $server): array;

    public function getSystemMetrics(VpnServer $server): array;
}
