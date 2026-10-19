<?php

namespace Tests\Feature;

use App\Models\VpnAccount;
use App\Models\VpnServer;
use App\Models\User;
use App\Services\Servers\AutomaticRemoteAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AutomaticRemoteAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reserves_unique_static_ips_and_public_ports_without_contacting_the_router(): void
    {
        $server = VpnServer::create([
            'name' => 'Dedicated CHR', 'adapter' => 'mikrotik-routeros', 'hostname' => 'vpn.example.test',
            'port' => 8733, 'status' => 'online',
            'encrypted_credentials' => Crypt::encryptString(json_encode([
                'routeros_remote_auto_enabled' => true, 'routeros_wan_interface' => 'ether1',
                'routeros_vpn_gateway' => '10.250.20.1', 'routeros_vpn_pool' => '10.250.20.128-10.250.20.254',
                'routeros_remote_ip_pool' => '10.250.20.10-10.250.20.12', 'routeros_vpn_subnet' => '10.250.20.0/24',
            ])),
        ]);
        $makeAccount = function () use ($server): VpnAccount {
            return VpnAccount::create([
                'uuid' => (string) Str::uuid(), 'idempotency_key' => (string) Str::uuid(),
                'vpn_server_id' => $server->id, 'protocol' => 'sstp', 'identifier' => (string) Str::uuid(),
                'status' => 'pending', 'remote_target_port' => 8291, 'remote_service' => 'mikrotik',
                'remote_allowed_cidr' => '203.0.113.10/32',
            ])->load('server');
        };
        $first = $makeAccount();
        $second = $makeAccount();
        $provisioner = app(AutomaticRemoteAccess::class);
        $blockedPorts = [20000 => true];
        $blockedIps = ['10.250.20.10' => true];
        $provisioner->prepare($first, $blockedPorts, $blockedIps);
        $provisioner->prepare($first, $blockedPorts, $blockedIps); // Retries retain the allocation.
        $provisioner->prepare($second, $blockedPorts, $blockedIps);

        $this->assertSame('10.250.20.11', $first->fresh()->allocated_ip);
        $this->assertSame(20001, $first->fresh()->remote_public_port);
        $this->assertSame('10.250.20.12', $second->fresh()->allocated_ip);
        $this->assertSame(20002, $second->fresh()->remote_public_port);
        $this->assertSame('pending', $second->fresh()->remote_rule_status);
        $this->expectException(\RuntimeException::class);
        $provisioner->prepare($makeAccount(), $blockedPorts, $blockedIps);
    }

    public function test_auto_remote_is_opt_in_per_server(): void
    {
        $server = VpnServer::create([
            'name' => 'Legacy CHR', 'adapter' => 'mikrotik-routeros', 'hostname' => 'vpn.example.test',
            'port' => 8733, 'status' => 'online',
        ]);
        $account = VpnAccount::create([
            'uuid' => (string) Str::uuid(), 'idempotency_key' => (string) Str::uuid(),
            'vpn_server_id' => $server->id, 'protocol' => 'sstp', 'identifier' => 'legacy',
            'status' => 'pending', 'remote_target_port' => 8291, 'remote_service' => 'mikrotik',
            'remote_allowed_cidr' => '203.0.113.10/32',
        ])->load('server');
        app(AutomaticRemoteAccess::class)->prepare($account);
        $this->assertNull($account->fresh()->remote_public_port);
    }

    public function test_admin_cannot_enable_remote_automation_before_chr_setup_is_verified(): void
    {
        $admin = User::factory()->create(['role' => 'administrator']);
        Sanctum::actingAs($admin);
        $server = VpnServer::create([
            'name' => 'CHR', 'adapter' => 'mikrotik-routeros', 'hostname' => 'vpn.example.test',
            'port' => 8733, 'status' => 'online',
            'encrypted_credentials' => Crypt::encryptString(json_encode(['routeros_username' => 'api-user', 'routeros_password' => 'secret'])),
        ]);
        $this->putJson('/api/servers/'.$server->id, ['credentials' => [
            'routeros_remote_auto_enabled' => true,
        ]])->assertUnprocessable()->assertJsonValidationErrors('credentials.routeros_wan_interface');

        $this->putJson('/api/servers/'.$server->id, ['credentials' => [
            'routeros_remote_auto_enabled' => true, 'routeros_wan_interface' => 'ether1',
            'routeros_vpn_gateway' => '10.250.20.1', 'routeros_vpn_pool' => '10.250.20.128-10.250.20.254',
            'routeros_remote_ip_pool' => '10.250.20.10-10.250.20.127', 'routeros_vpn_subnet' => '10.250.20.0/24',
        ]])->assertUnprocessable()->assertJsonValidationErrors('credentials.routeros_remote_auto_enabled');
        $this->getJson('/api/servers/'.$server->id.'/remote-settings')->assertOk()
            ->assertJsonPath('enabled', false)
            ->assertDontSee('api-user')->assertDontSee('secret');
    }
}
