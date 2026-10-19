<?php

namespace Tests\Feature;

use App\Jobs\ProvisionVpnAccount;
use App\Models\BillingSetting;
use App\Models\Plan;
use App\Models\User;
use App\Models\VpnAccount;
use App\Models\VpnServer;
use App\Services\Servers\DriverFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LinuxOpenVpnIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_openvpn_remote_requires_a_restricted_source_and_queues_valid_accounts(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response(['vpn_services' => ['openvpn' => ['status' => 'online', 'port' => 1196]]])]);
        $admin = User::factory()->create(['role' => 'administrator']);
        Sanctum::actingAs($admin);
        $server = VpnServer::create(['name' => 'Linux', 'adapter' => 'linux-openvpn', 'hostname' => 'vpn.example.test',
            'port' => 1196, 'status' => 'online', 'capabilities' => ['protocols' => ['openvpn']],
            'encrypted_credentials' => Crypt::encryptString(json_encode(['agent_shared_secret' => str_repeat('a', 64)]))]);
        $payload = ['vpn_server_id' => $server->id, 'protocol' => 'openvpn', 'identifier' => 'manual-test',
            'credentials' => ['password' => 'unique-password-123'], 'remote_service' => 'mikrotik', 'remote_target_port' => 8291];
        $this->postJson('/api/accounts', $payload)->assertUnprocessable();
        $this->postJson('/api/accounts', $payload + ['remote_allowed_cidr' => '0.0.0.0/0'])->assertUnprocessable();
        $this->postJson('/api/accounts', array_merge($payload, ['identifier' => 'DEFAULT', 'remote_allowed_cidr' => '203.0.113.10/32']))->assertUnprocessable();
        $this->postJson('/api/accounts', $payload + ['remote_allowed_cidr' => '203.0.113.10/32'])->assertAccepted()
            ->assertJsonPath('remote_allowed_cidr', '203.0.113.10/32')->assertJsonMissingPath('encrypted_provisioning_secret');
        Queue::assertPushed(ProvisionVpnAccount::class);
    }

    public function test_customer_openvpn_order_provisions_allocations_and_downloads_a_private_client_profile(): void
    {
        Queue::fake();
        $secret = str_repeat('a', 64);
        Http::fake(function (Request $request) {
            if (str_ends_with($request->url(), '/v1/health')) {
                return Http::response(['status' => 'online', 'vpn_services' => ['openvpn' => ['status' => 'online', 'port' => 1196]]]);
            }
            if (str_ends_with($request->url(), '/v1/client-template')) {
                return Http::response(['ca' => "-----BEGIN CERTIFICATE-----\nQUJD\n-----END CERTIFICATE-----", 'cert_name' => 'vpn.example.test']);
            }
            return Http::response(['status' => 'success', 'remote_reference' => $request['account_uuid'], 'data' => [
                'allocated_ip' => '10.77.0.10', 'remote_public_port' => 20001, 'remote_target_port' => 8291,
                'remote_allowed_cidr' => '203.0.113.10/32', 'remote_gateway_ip' => '10.77.0.1',
                'remote_wan_interface' => 'eth0', 'remote_rule_status' => 'active',
            ]]);
        });
        $admin = User::factory()->create(['role' => 'administrator']);
        $customer = User::factory()->create(['role' => 'customer', 'email_verified_at' => now()]);
        $server = VpnServer::create([
            'name' => 'Linux OpenVPN', 'adapter' => 'linux-openvpn', 'hostname' => 'vpn.example.test', 'port' => 1196,
            'status' => 'online', 'capabilities' => ['protocols' => ['openvpn']],
            'encrypted_credentials' => Crypt::encryptString(json_encode(['agent_shared_secret' => $secret, 'agent_port' => 9444])),
        ]);
        $plan = Plan::create(['name' => 'OpenVPN 30', 'price_cents' => 10000, 'currency' => 'IDR', 'duration_days' => 30,
            'allowed_protocols' => ['openvpn'], 'connection_limit' => 1, 'is_active' => true]);
        BillingSetting::create(['bank_name' => 'Test', 'account_name' => 'virVPN', 'account_number' => '123']);
        Sanctum::actingAs($customer);
        $this->getJson('/api/customer/catalog')->assertOk()->assertJsonPath('servers.0.protocols', ['openvpn']);
        $order = $this->postJson('/api/customer/orders', [
            'plan_id' => $plan->id, 'vpn_server_id' => $server->id, 'protocol' => 'openvpn', 'identifier' => 'linux-test',
            'client_password' => 'unique-password-123', 'client_device_type' => 'windows', 'remote_service' => 'mikrotik',
            'remote_target_port' => 8291, 'remote_allowed_cidr' => '203.0.113.10/32',
        ])->assertCreated()->json('id');
        $this->postJson('/api/customer/orders/'.$order.'/payment', ['payment_reference' => 'BANK-100'])->assertOk();
        Sanctum::actingAs($admin);
        $this->postJson('/api/billing/orders/'.$order.'/confirm')->assertOk();
        $account = VpnAccount::sole();
        (new ProvisionVpnAccount($account->id, $admin->id))->handle(app(DriverFactory::class));
        $account->refresh();
        $this->assertSame('active', $account->status);
        $this->assertSame('10.77.0.10', $account->allocated_ip);
        $this->assertSame(20001, $account->remote_public_port);
        $this->assertNull($account->encrypted_provisioning_secret);
        Http::assertSent(function (Request $request) use ($secret) {
            if (! str_ends_with($request->url(), '/v1/vpn/accounts')) return false;
            $message = implode("\n", ['POST', '/v1/vpn/accounts', $request->header('X-Agent-Time')[0], $request->header('X-Agent-Nonce')[0], $request->body()]);
            return hash_equals(hash_hmac('sha256', $message, $secret), $request->header('X-Agent-Signature')[0])
                && $request['password'] === 'unique-password-123';
        });
        Sanctum::actingAs($customer);
        $this->get('/api/customer/accounts/'.$account->id.'/client-config')->assertOk()
            ->assertSee('remote vpn.example.test 1196')->assertSee('remote-cert-tls server')->assertDontSee('unique-password-123');
        $this->getJson('/api/customer/accounts')->assertOk()->assertJsonPath('data.0.server.adapter', 'linux-openvpn');
        Sanctum::actingAs(User::factory()->create(['role' => 'customer', 'email_verified_at' => now()]));
        $this->getJson('/api/customer/accounts/'.$account->id.'/client-config')->assertNotFound();
        Sanctum::actingAs($admin);
        $this->postJson('/api/accounts/'.$account->id.'/suspend')->assertOk()->assertJsonPath('remote_rule_status', 'suspended');
        $this->postJson('/api/accounts/'.$account->id.'/activate')->assertOk()->assertJsonPath('remote_rule_status', 'active');
        $this->postJson('/api/accounts/'.$account->id.'/revoke')->assertOk()->assertJsonPath('remote_rule_status', 'removed');
    }
}
