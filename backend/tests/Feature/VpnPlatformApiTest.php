<?php

namespace Tests\Feature;

use App\Jobs\ExpireVpnAccount;
use App\Models\Plan;
use App\Models\User;
use App\Models\VpnAccount;
use App\Models\VpnServer;
use App\Services\Servers\DriverFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VpnPlatformApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_registration_requires_email_verification_before_sign_in(): void
    {
        Notification::fake();

        $this->postJson('/api/auth/register', [
            'name' => 'Customer One',
            'email' => 'customer@example.test',
            'password' => 'customer-secure-password',
            'password_confirmation' => 'customer-secure-password',
        ])->assertCreated()->assertJsonPath('email', 'customer@example.test')->assertJsonPath('verification_required', true)->assertJsonMissing(['token']);

        $customer = User::where('email', 'customer@example.test')->firstOrFail();
        $this->assertSame('customer', $customer->role);
        $this->assertNull($customer->email_verified_at);
        Notification::assertSentTo($customer, VerifyEmail::class);

        $this->postJson('/api/auth/login', ['email' => $customer->email, 'password' => 'customer-secure-password'])
            ->assertForbidden()->assertJsonPath('code', 'email_verification_required');

        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(10), [
            'id' => $customer->id,
            'hash' => sha1($customer->getEmailForVerification()),
        ]);
        $this->get($url)->assertRedirect(config('app.frontend_url').'?email_verified=1');

        $this->assertNotNull($customer->fresh()->email_verified_at);
        $this->postJson('/api/auth/login', ['email' => $customer->email, 'password' => 'customer-secure-password'])
            ->assertOk()->assertJsonPath('user.role', 'customer')->assertJsonStructure(['token']);
    }

    public function test_administrator_can_log_in_and_read_dashboard(): void
    {
        $user = User::factory()->create(['email' => 'admin@example.test', 'password' => 'password']);
        $login = $this->postJson('/api/auth/login', ['email' => 'admin@example.test', 'password' => 'password']);
        $login->assertOk()->assertJsonPath('user.id', $user->id)->assertJsonStructure(['token']);
        $this->withToken($login->json('token'))->getJson('/api/dashboard')->assertOk()->assertJsonStructure(['summary', 'recent_operations', 'demo_data']);
    }

    public function test_administrator_can_update_profile_and_password(): void
    {
        $user = User::factory()->create(['email' => 'admin@example.test', 'password' => 'current-password']);
        $login = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'current-password']);
        $token = $login->json('token');
        $otherToken = $user->createToken('another-device')->accessToken;

        $this->withToken($token)->patchJson('/api/auth/profile', [
            'name' => 'VPN Administrator',
            'email' => 'operator@example.test',
        ])->assertOk()->assertJsonPath('name', 'VPN Administrator')->assertJsonPath('email', 'operator@example.test');

        $this->withToken($token)->postJson('/api/auth/password', [
            'current_password' => 'current-password',
            'password' => 'a-new-secure-password',
            'password_confirmation' => 'a-new-secure-password',
        ])->assertOk()->assertJsonPath('message', 'Password updated. Other active sessions have been signed out.');

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $otherToken->id]);
        $this->postJson('/api/auth/login', ['email' => 'operator@example.test', 'password' => 'current-password'])->assertUnprocessable();
        $this->postJson('/api/auth/login', ['email' => 'operator@example.test', 'password' => 'a-new-secure-password'])->assertOk();
    }

    public function test_account_provisioning_uses_the_server_driver_and_is_idempotent(): void
    {
        $admin = User::factory()->create();
        Sanctum::actingAs($admin);
        $server = VpnServer::create(['name' => 'Demo edge', 'adapter' => 'demo', 'hostname' => 'demo.invalid', 'port' => 443, 'capabilities' => ['protocols' => ['wireguard']], 'is_demo' => true]);
        $plan = Plan::create(['name' => 'Demo', 'price_cents' => 10000, 'currency' => 'IDR', 'duration_days' => 30, 'allowed_protocols' => ['wireguard'], 'connection_limit' => 1]);
        $headers = ['Idempotency-Key' => 'af1e94e4-823a-4ed5-83f7-eefa8237a7a1'];
        $payload = ['vpn_server_id' => $server->id, 'plan_id' => $plan->id, 'protocol' => 'wireguard', 'identifier' => 'integration-peer'];
        $first = $this->withHeaders($headers)->postJson('/api/accounts', $payload);
        $first->assertStatus(202)->assertJsonPath('status', 'active');
        $this->withHeaders($headers)->postJson('/api/accounts', $payload)->assertOk()->assertJsonPath('id', $first->json('id'));
        $this->assertDatabaseCount('vpn_accounts', 1);
        $this->assertDatabaseHas('operation_logs', ['operation' => 'create_account', 'status' => 'success']);
    }

    public function test_non_administrator_cannot_manage_the_control_plane(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'viewer']));

        $this->getJson('/api/dashboard')
            ->assertForbidden()
            ->assertJsonPath('message', 'Administrator access is required.');
    }

    public function test_unauthenticated_api_requests_return_json_instead_of_a_missing_web_login_route(): void
    {
        $this->get('/api/servers')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_routeros_registration_requires_a_restricted_api_and_encrypts_its_credentials(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $payload = [
            'name' => 'Jakarta CHR', 'adapter' => 'mikrotik-routeros', 'hostname' => '198.51.100.20', 'port' => 8729,
            'tls_verify' => true, 'capabilities' => ['protocols' => ['l2tp-ipsec']],
            'credentials' => ['routeros_username' => 'vpnops-api', 'routeros_password' => 'safe-api-password', 'routeros_api_ssl' => true],
        ];

        $this->postJson('/api/servers', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('credentials.routeros_allowed_cidr');

        $payload['credentials']['routeros_allowed_cidr'] = '203.0.113.10/32';
        $payload['credentials']['routeros_certificate'] = 'vpnops-api';
        $payload['credentials']['routeros_allowed_cidr'] = '198.51.100.20/32';
        $this->postJson('/api/servers', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('credentials.routeros_allowed_cidr');

        $payload['credentials']['routeros_allowed_cidr'] = '203.0.113.10/32';
        $response = $this->postJson('/api/servers', $payload);

        $response->assertCreated()->assertJsonMissingPath('encrypted_credentials');
        $server = VpnServer::query()->sole();
        $this->assertNotSame('safe-api-password', $server->encrypted_credentials);
        $this->assertSame($payload['credentials'], json_decode(Crypt::decryptString($server->encrypted_credentials), true, 512, JSON_THROW_ON_ERROR));
    }

    public function test_expiry_revokes_a_demo_account_before_marking_it_expired(): void
    {
        $server = VpnServer::create(['name' => 'Demo edge', 'adapter' => 'demo', 'hostname' => 'demo.invalid', 'port' => 443, 'capabilities' => ['protocols' => ['wireguard']], 'is_demo' => true]);
        $account = VpnAccount::create([
            'uuid' => 'ee3fd6e5-f338-40d7-9c86-bbf3d48d346b', 'vpn_server_id' => $server->id,
            'protocol' => 'wireguard', 'identifier' => 'expired-peer', 'status' => 'expiring',
            'expires_at' => now()->subMinute(), 'idempotency_key' => '03d2be53-1e1a-4439-bf6c-73cfa8dd532d',
        ]);

        (new ExpireVpnAccount($account->id))->handle(app(DriverFactory::class));

        $this->assertDatabaseHas('vpn_accounts', ['id' => $account->id, 'status' => 'expired']);
        $this->assertDatabaseHas('operation_logs', ['vpn_account_id' => $account->id, 'operation' => 'expire_account', 'status' => 'success']);
    }
}
