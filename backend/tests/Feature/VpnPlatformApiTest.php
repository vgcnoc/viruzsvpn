<?php

namespace Tests\Feature;

use App\Jobs\ExpireVpnAccount;
use App\Jobs\ProvisionVpnAccount;
use App\Models\Plan;
use App\Models\User;
use App\Models\VpnAccount;
use App\Models\VpnServer;
use App\Services\Servers\DriverFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
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

    public function test_manual_routeros_provisioning_rejects_unsupported_protocols_and_duplicate_names(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $server = VpnServer::create([
            'name' => 'Ready CHR', 'adapter' => 'mikrotik-routeros', 'hostname' => 'chr.example.test',
            'port' => 8729, 'status' => 'online', 'capabilities' => ['protocols' => ['wireguard', 'sstp', 'l2tp-ipsec']],
            'encrypted_credentials' => Crypt::encryptString(json_encode(['routeros_username' => 'api', 'routeros_password' => 'private'], JSON_THROW_ON_ERROR)),
        ]);
        Cache::put('virvpn:provisionable-protocols:'.$server->id, ['sstp', 'l2tp-ipsec'], 60);

        $this->getJson('/api/servers/'.$server->id.'/provisioning-options')
            ->assertOk()->assertJsonPath('protocols', ['sstp']);
        $this->postJson('/api/accounts', [
            'vpn_server_id' => $server->id, 'protocol' => 'wireguard', 'identifier' => 'client-one',
        ])->assertUnprocessable();
        $this->assertDatabaseCount('vpn_accounts', 0);

        Queue::fake();
        $payload = [
            'vpn_server_id' => $server->id, 'protocol' => 'sstp', 'identifier' => 'client-one',
            'expires_at' => now()->addDays(30)->toISOString(), 'credentials' => ['password' => 'very-secure-password'],
            'remote_target_port' => 8291,
            'remote_service' => 'mikrotik',
        ];
        $this->postJson('/api/accounts', $payload)->assertStatus(202)->assertJsonPath('status', 'pending')->assertJsonPath('remote_target_port', 8291);
        Queue::assertPushed(ProvisionVpnAccount::class);
        $this->assertDatabaseCount('vpn_accounts', 1);
        $this->postJson('/api/accounts', $payload)->assertUnprocessable();
    }

    public function test_manual_plan_starts_when_provisioning_succeeds(): void
    {
        Queue::fake();
        Sanctum::actingAs(User::factory()->create());
        $server = VpnServer::create([
            'name' => 'Demo edge', 'adapter' => 'demo', 'hostname' => 'demo.invalid', 'port' => 443,
            'capabilities' => ['protocols' => ['wireguard']], 'is_demo' => true,
        ]);
        $plan = Plan::create([
            'name' => 'Manual 30', 'price_cents' => 50000, 'currency' => 'IDR',
            'duration_days' => 30, 'allowed_protocols' => ['wireguard'], 'connection_limit' => 1,
        ]);
        $accountId = $this->postJson('/api/accounts', [
            'vpn_server_id' => $server->id, 'plan_id' => $plan->id,
            'protocol' => 'wireguard', 'identifier' => 'manual-peer',
        ])->assertStatus(202)->json('id');
        $account = VpnAccount::findOrFail($accountId);
        $this->assertSame('pending_activation', $account->subscriptions()->firstOrFail()->status);

        (new ProvisionVpnAccount($accountId))->handle(app(DriverFactory::class));

        $account->refresh();
        $this->assertSame('active', $account->status);
        $this->assertSame('active', $account->subscriptions()->firstOrFail()->status);
        $this->assertSame(30, (int) round($account->starts_at->diffInDays($account->expires_at)));
    }

    public function test_non_administrator_cannot_manage_the_control_plane(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'viewer']));

        $this->getJson('/api/dashboard')
            ->assertForbidden()
            ->assertJsonPath('message', 'Administrator access is required.');
    }

    public function test_routeros_remote_access_requires_a_stable_ip_unique_port_and_restricted_source(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $server = VpnServer::create([
            'name' => 'Remote CHR', 'adapter' => 'mikrotik-routeros', 'hostname' => 'vpn.example.test',
            'port' => 8729, 'capabilities' => ['protocols' => ['sstp']],
        ]);
        $first = VpnAccount::create([
            'uuid' => 'f28e8bd4-864f-42e4-bf2a-8d193a49a26d', 'vpn_server_id' => $server->id,
            'protocol' => 'sstp', 'identifier' => 'first-router', 'status' => 'active',
            'allocated_ip' => '10.250.20.10', 'idempotency_key' => '3aabf879-9242-485f-b2c5-d3bf5a01109c',
        ]);
        $second = VpnAccount::create([
            'uuid' => '888e8bd4-864f-42e4-bf2a-8d193a49a26d', 'vpn_server_id' => $server->id,
            'protocol' => 'sstp', 'identifier' => 'second-router', 'status' => 'active',
            'allocated_ip' => '10.250.20.11', 'idempotency_key' => '4aabf879-9242-485f-b2c5-d3bf5a01109c',
        ]);
        $mapping = [
            'remote_public_port' => 28291, 'remote_target_port' => 8291,
            'remote_service' => 'mikrotik',
            'remote_allowed_cidr' => '203.0.113.10/32', 'remote_wan_interface' => 'ether1',
            'remote_gateway_ip' => '10.250.20.1',
        ];

        $this->putJson('/api/accounts/'.$first->id.'/remote-access', $mapping)
            ->assertOk()->assertJsonPath('remote_public_port', 28291)->assertJsonPath('remote_target_port', 8291);
        $this->putJson('/api/accounts/'.$second->id.'/remote-access', $mapping)
            ->assertUnprocessable()->assertJsonValidationErrors('remote_public_port');
        $this->putJson('/api/accounts/'.$second->id.'/remote-access', [
            ...$mapping, 'remote_public_port' => 28292, 'remote_allowed_cidr' => '0.0.0.0/0',
        ])->assertUnprocessable()->assertJsonValidationErrors('remote_allowed_cidr');
        $this->putJson('/api/accounts/'.$second->id.'/remote-access', [
            ...$mapping, 'remote_public_port' => 28292, 'remote_gateway_ip' => $second->allocated_ip,
        ])->assertUnprocessable();
        $this->assertDatabaseHas('vpn_accounts', ['id' => $first->id, 'remote_public_port' => 28291]);
    }

    public function test_monitoring_does_not_invent_offline_status_when_session_telemetry_is_unsupported(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $server = VpnServer::create([
            'name' => 'Demo edge', 'adapter' => 'demo', 'hostname' => 'demo.invalid', 'port' => 443,
            'capabilities' => ['protocols' => ['sstp']], 'is_demo' => true,
        ]);
        $account = VpnAccount::create([
            'uuid' => 'b62a3504-6793-460c-8b73-67880171659f', 'vpn_server_id' => $server->id,
            'protocol' => 'sstp', 'identifier' => 'monitor-client', 'status' => 'active',
            'idempotency_key' => '42d6f0ae-e3c7-4cbe-977c-4473c01e9f0d',
        ]);

        $this->getJson('/api/monitoring')->assertOk()
            ->assertJsonPath('summary.total', 1)
            ->assertJsonPath('summary.offline', 0)
            ->assertJsonPath('summary.unknown', 1)
            ->assertJsonPath('summary.unmanaged_sessions', 0)
            ->assertJsonPath('accounts.data.0.connection', 'unknown')
            ->assertJsonPath('servers.0.status', 'unsupported');
        $this->getJson('/api/monitoring?state=unknown')->assertOk()->assertJsonPath('accounts.total', 1);
        $this->getJson('/api/monitoring?state=offline')->assertOk()->assertJsonPath('accounts.total', 0);
        $this->postJson('/api/monitoring/accounts/'.$account->id.'/disconnect', ['session_id' => '*1'])
            ->assertUnprocessable();
        $this->assertDatabaseHas('operation_logs', ['vpn_account_id' => $account->id, 'operation' => 'disconnect_session', 'status' => 'failed']);
    }

    public function test_customer_cannot_access_monitoring_or_disconnect_sessions(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'customer']));
        $server = VpnServer::create(['name' => 'Demo edge', 'adapter' => 'demo', 'hostname' => 'demo.invalid', 'port' => 443]);
        $account = VpnAccount::create([
            'uuid' => 'db25dd0d-1322-4af7-82b2-520e60b78075', 'vpn_server_id' => $server->id,
            'protocol' => 'sstp', 'identifier' => 'private-client', 'status' => 'active',
            'idempotency_key' => '4333ae1e-bd57-4a2c-b0de-b037378a68c3',
        ]);
        $this->getJson('/api/monitoring')->assertForbidden();
        $this->postJson('/api/monitoring/accounts/'.$account->id.'/disconnect', ['session_id' => '*1'])->assertForbidden();
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

        $this->putJson('/api/servers/'.$server->id, [
            'name' => $server->name,
            'adapter' => 'mikrotik-routeros',
            'hostname' => $server->hostname,
            'port' => $server->port,
            'capabilities' => ['protocols' => ['l2tp-ipsec', 'sstp']],
            'credentials' => ['routeros_ppp_profile' => 'virvpn-standard'],
        ])->assertOk();

        $storedCredentials = json_decode(Crypt::decryptString($server->fresh()->encrypted_credentials), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('safe-api-password', $storedCredentials['routeros_password']);
        $this->assertSame('virvpn-standard', $storedCredentials['routeros_ppp_profile']);
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
