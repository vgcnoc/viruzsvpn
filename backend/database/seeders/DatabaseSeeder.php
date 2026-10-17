<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Models\User;
use App\Models\VpnAccount;
use App\Models\VpnServer;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::factory()->create([
            'name' => 'Platform Administrator',
            'email' => 'admin@vpn.local',
            'role' => 'administrator',
        ]);

        $server = VpnServer::create([
            'name' => 'Jakarta Demo Edge', 'adapter' => 'demo', 'hostname' => 'demo.invalid', 'port' => 443,
            'location' => 'Jakarta, ID', 'status' => 'online', 'vpn_status' => 'healthy', 'last_checked_at' => now(),
            'capabilities' => ['protocols' => ['wireguard', 'openvpn', 'l2tp-ipsec']], 'is_demo' => true,
        ]);
        $plan = Plan::create([
            'name' => 'Professional 30', 'description' => 'Demo package for local development.', 'price_cents' => 89000,
            'currency' => 'IDR', 'duration_days' => 30, 'allowed_protocols' => ['wireguard', 'openvpn'],
            'connection_limit' => 3, 'bandwidth_mbps' => 100, 'is_active' => true,
        ]);
        VpnAccount::create([
            'uuid' => (string) Str::uuid(), 'vpn_server_id' => $server->id, 'plan_id' => $plan->id,
            'protocol' => 'wireguard', 'identifier' => 'demo-peer-01', 'status' => 'active', 'starts_at' => now()->subDays(3),
            'expires_at' => now()->addDays(27), 'device_limit' => 3, 'bandwidth_mbps' => 100,
            'remote_reference' => 'demo-peer-01', 'idempotency_key' => (string) Str::uuid(),
        ]);
    }
}
