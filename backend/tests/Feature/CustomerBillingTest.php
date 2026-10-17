<?php

namespace Tests\Feature;

use App\Jobs\ProvisionVpnAccount;
use App\Models\Plan;
use App\Models\User;
use App\Models\VpnAccount;
use App\Models\VpnServer;
use App\Services\Servers\DriverFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomerBillingTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $admin = User::factory()->create(['role' => 'administrator']);
        $customer = User::factory()->create(['role' => 'customer', 'email_verified_at' => now()]);
        $server = VpnServer::create([
            'name' => 'Sukabumi', 'adapter' => 'mikrotik-routeros', 'hostname' => 'vpn.example.test',
            'port' => 8729, 'status' => 'online', 'capabilities' => ['protocols' => ['l2tp-ipsec', 'sstp']],
            'encrypted_credentials' => Crypt::encryptString(json_encode(['routeros_l2tp_secret' => 'server-pre-shared-key-123'])),
        ]);
        Cache::put('virvpn:sellable-protocols:'.$server->id, ['l2tp-ipsec'], 60);
        $plan = Plan::create([
            'name' => 'Basic 30', 'price_cents' => 45000, 'currency' => 'IDR', 'duration_days' => 30,
            'allowed_protocols' => ['l2tp-ipsec'], 'connection_limit' => 1, 'is_active' => true,
        ]);
        Sanctum::actingAs($admin);
        $this->putJson('/api/billing/settings', [
            'bank_name' => 'Bank Test', 'account_name' => 'virVPN', 'account_number' => '12345678',
        ])->assertOk()->assertJsonPath('payment_enabled', true);

        return [$admin, $customer, $server, $plan];
    }

    public function test_customer_order_only_provisions_after_admin_confirms_payment(): void
    {
        Queue::fake();
        [$admin, $customer, $server, $plan] = $this->fixture();
        Sanctum::actingAs($customer);
        $this->getJson('/api/customer/catalog')->assertOk()->assertJsonCount(1, 'plans')->assertJsonCount(1, 'servers');
        $response = $this->postJson('/api/customer/orders', [
            'plan_id' => $plan->id, 'vpn_server_id' => $server->id,
            'protocol' => 'l2tp-ipsec', 'identifier' => 'customer-001',
            'client_password' => 'unique-vpn-password-123',
        ]);
        $response->assertCreated()->assertJsonPath('status', 'pending_payment')->assertJsonMissingPath('encrypted_client_password');
        $orderId = $response->json('id');
        $this->assertDatabaseCount('vpn_accounts', 0);
        Queue::assertNothingPushed();
        $this->postJson("/api/billing/orders/$orderId/confirm")->assertForbidden();
        $this->postJson("/api/customer/orders/$orderId/payment", ['payment_reference' => 'BANK-TRX-1001'])
            ->assertOk()->assertJsonPath('status', 'payment_submitted');
        $other = User::factory()->create(['role' => 'customer', 'email_verified_at' => now()]);
        Sanctum::actingAs($other);
        $this->getJson('/api/customer/orders')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson("/api/customer/orders/$orderId/cancel")->assertNotFound();

        Sanctum::actingAs($admin);
        $this->postJson("/api/billing/orders/$orderId/confirm")->assertOk()->assertJsonPath('status', 'paid');
        $this->postJson("/api/billing/orders/$orderId/confirm")->assertStatus(409);
        $account = VpnAccount::query()->sole();
        $this->assertSame($customer->id, $account->user_id);
        $this->assertSame('unique-vpn-password-123', Crypt::decryptString($account->encrypted_provisioning_secret));
        $this->assertDatabaseCount('subscriptions', 1);
        $this->assertDatabaseHas('subscriptions', ['vpn_account_id' => $account->id, 'status' => 'pending_activation']);
        $this->assertDatabaseHas('operation_logs', ['vpn_account_id' => $account->id, 'operation' => 'confirm_payment', 'user_id' => $admin->id]);
        Queue::assertPushed(ProvisionVpnAccount::class, 1);
        $this->assertNull($account->expires_at);
        $server->update(['adapter' => 'demo', 'is_demo' => true]);
        (new ProvisionVpnAccount($account->id, $admin->id))->handle(app(DriverFactory::class));
        $this->assertSame('active', $account->fresh()->status);
        $this->assertNotNull($account->fresh()->expires_at);
        $this->assertNull($account->fresh()->encrypted_provisioning_secret);
        $this->assertDatabaseHas('subscriptions', ['vpn_account_id' => $account->id, 'status' => 'active']);
    }

    public function test_customer_cannot_order_ineligible_protocol_or_unverified_account(): void
    {
        [, $customer, $server, $plan] = $this->fixture();
        Sanctum::actingAs($customer);
        $this->postJson('/api/customer/orders', [
            'plan_id' => $plan->id, 'vpn_server_id' => $server->id,
            'protocol' => 'sstp', 'identifier' => 'client-1', 'client_password' => 'unique-vpn-password-123',
        ])->assertUnprocessable();
        $customer->forceFill(['email_verified_at' => null])->save();
        $this->getJson('/api/customer/catalog')->assertForbidden();
    }

    public function test_paid_customer_can_request_one_renewal_and_admin_extends_existing_service(): void
    {
        Queue::fake();
        [$admin, $customer, $server, $plan] = $this->fixture();
        $account = VpnAccount::create([
            'uuid' => '26993177-ff5f-40c3-93b0-d0c7152f72a8',
            'idempotency_key' => '964a8f6a-23ab-4b72-a84a-74dfb674d3b8',
            'user_id' => $customer->id, 'vpn_server_id' => $server->id, 'plan_id' => $plan->id,
            'protocol' => 'l2tp-ipsec', 'identifier' => 'renewal-client', 'status' => 'active',
            'starts_at' => now()->subDays(2), 'expires_at' => now()->addDays(28),
        ]);
        $oldExpiry = $account->expires_at->copy();
        Sanctum::actingAs($customer);
        $this->getJson('/api/customer/accounts')->assertOk()->assertJsonPath('data.0.identifier', 'renewal-client');
        $response = $this->postJson('/api/customer/accounts/'.$account->id.'/renew')->assertCreated()->assertJsonPath('type', 'renewal');
        $orderId = $response->json('id');
        $this->postJson('/api/customer/accounts/'.$account->id.'/renew')->assertStatus(409);
        $this->postJson("/api/customer/orders/$orderId/payment", ['payment_reference' => 'BANK-RENEW-1002'])->assertOk();
        Sanctum::actingAs($admin);
        $this->postJson("/api/billing/orders/$orderId/confirm")->assertOk()->assertJsonPath('status', 'paid');
        $this->assertDatabaseCount('vpn_accounts', 1);
        $this->assertDatabaseCount('subscriptions', 1);
        $this->assertTrue($account->fresh()->expires_at->equalTo($oldExpiry->addDays(30)));
        Queue::assertNothingPushed();
    }
}
