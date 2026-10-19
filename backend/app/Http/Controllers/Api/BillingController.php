<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProvisionVpnAccount;
use App\Models\BillingSetting;
use App\Models\OperationLog;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\VpnAccount;
use App\Models\VpnOrder;
use App\Models\VpnServer;
use App\Services\Servers\AccountProvisioning;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BillingController extends Controller
{
    private const CUSTOMER_PROTOCOLS = ['l2tp-ipsec', 'sstp', 'openvpn', 'wireguard'];

    public function catalog(AccountProvisioning $provisioning)
    {
        return response()->json([
            'plans' => Plan::where('is_active', true)->orderBy('price_cents')->get(),
            'servers' => VpnServer::query()->where('status', 'online')->where('is_demo', false)
                ->whereIn('adapter', ['mikrotik-routeros', 'linux-openvpn', 'linux-agent'])->limit(50)->get(['id', 'name', 'hostname', 'port', 'location', 'adapter', 'status', 'capabilities', 'encrypted_credentials', 'tls_verify'])
                ->map(fn ($server) => [
                    'id' => $server->id, 'name' => $server->name, 'location' => $server->location,
                    'protocols' => array_values(array_intersect($provisioning->availableProtocols($server), match ($server->adapter) {
                        'linux-openvpn' => ['openvpn'],
                        'linux-agent' => ['wireguard'],
                        default => ['l2tp-ipsec', 'sstp'],
                    })),
                ])->filter(fn ($server) => count($server['protocols']) > 0)->values(),
        ]);
    }

    public function settings()
    {
        $settings = BillingSetting::first();

        return response()->json([
            'bank_name' => $settings?->bank_name,
            'account_name' => $settings?->account_name,
            'account_number' => $settings?->account_number,
            'instructions' => $settings?->instructions,
            'payment_enabled' => (bool) ($settings?->bank_name && $settings?->account_name && $settings?->account_number),
        ]);
    }

    public function updateSettings(Request $request)
    {
        $data = $request->validate([
            'bank_name' => ['required', 'string', 'max:100'],
            'account_name' => ['required', 'string', 'max:150'],
            'account_number' => ['required', 'string', 'max:80'],
            'instructions' => ['nullable', 'string', 'max:2000'],
        ]);
        BillingSetting::query()->firstOrCreate(['id' => 1])->update($data);

        return $this->settings();
    }

    public function myOrders(Request $request)
    {
        return VpnOrder::where('user_id', $request->user()->id)
            ->with(['server:id,name', 'account:id,status,expires_at,identifier'])
            ->latest()->paginate(30);
    }

    public function myAccounts(Request $request)
    {
        return VpnAccount::where('user_id', $request->user()->id)
            ->with(['server:id,name,hostname,port,adapter,capabilities,encrypted_credentials', 'plan:id,name,duration_days'])
            ->latest()->paginate(30)->through(function (VpnAccount $account) {
                if ($account->protocol === 'l2tp-ipsec' && $account->status === 'active' && $account->server?->encrypted_credentials) {
                    $credentials = json_decode(Crypt::decryptString($account->server->encrypted_credentials), true, 512, JSON_THROW_ON_ERROR);
                    $account->setAttribute('l2tp_psk', $credentials['routeros_l2tp_secret'] ?? null);
                }

                return $account;
            });
    }

    public function createOrder(Request $request, AccountProvisioning $provisioning)
    {
        $data = $request->validate([
            'plan_id' => ['required', 'integer', 'exists:plans,id'],
            'vpn_server_id' => ['required', 'integer', 'exists:vpn_servers,id'],
            'protocol' => ['required', Rule::in(self::CUSTOMER_PROTOCOLS)],
            'identifier' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9._-]+$/'],
            'client_password' => ['nullable', 'required_unless:protocol,wireguard', 'string', 'min:12', 'max:255'],
            'wireguard_public_key' => ['nullable', 'required_if:protocol,wireguard', 'string', 'regex:/^[A-Za-z0-9+\\/]{43}=$/'],
            'client_device_type' => ['nullable', Rule::in(['mikrotik', 'windows', 'android', 'ios', 'linux', 'cctv', 'other'])],
            'remote_service' => ['nullable', 'required_with:remote_target_port', Rule::in(['mikrotik', 'olt', 'olt-https', 'cctv', 'cctv-https', 'ssh', 'https', 'custom'])],
            'remote_target_port' => ['nullable', 'required_with:remote_service', 'integer', 'between:1,65535'],
            'remote_device_ip' => ['nullable', 'required_if:remote_service,olt,olt-https', 'ipv4'],
            'remote_allowed_cidr' => ['nullable', 'required_with:remote_target_port', 'string', 'max:18', function ($attribute, $value, $fail) {
                $parts = explode('/', (string) $value);
                if (count($parts) !== 2 || $parts[1] !== '32' || ! filter_var($parts[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $fail('Enter the public IPv4 address allowed to use the remote port as an /32 CIDR.');
                }
            }],
        ]);
        $payment = BillingSetting::first();
        abort_unless($payment?->bank_name && $payment?->account_name && $payment?->account_number, 409, 'Payment instructions are not configured yet.');
        $plan = Plan::findOrFail($data['plan_id']);
        $server = VpnServer::findOrFail($data['vpn_server_id']);
        if ($server->adapter === 'linux-openvpn') {
            abort_unless(preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $data['identifier']) && $data['identifier'] !== 'DEFAULT', 422,
                'OpenVPN usernames must start with a letter or number and cannot be DEFAULT.');
            abort_if(preg_match('/[\x00-\x1F\x7F]/', $data['client_password']), 422, 'VPN passwords cannot contain control characters.');
        }
        abort_unless($plan->is_active && in_array($data['protocol'], $plan->allowed_protocols ?? [], true), 422, 'This plan does not include the chosen protocol.');
        abort_unless(! $plan->allowed_server_ids || in_array($server->id, $plan->allowed_server_ids, true), 422, 'This server is not available for the plan.');
        abort_unless($server->status === 'online' && ! $server->is_demo && in_array($server->adapter, ['mikrotik-routeros', 'linux-openvpn', 'linux-agent'], true)
            && ($data['protocol'] !== 'openvpn' || $server->adapter === 'linux-openvpn')
            && ($data['protocol'] !== 'wireguard' || $server->adapter === 'linux-agent')
            && in_array($data['protocol'], $server->capabilities['protocols'] ?? [], true)
            && in_array($data['protocol'], $provisioning->availableProtocols($server), true), 422, 'This VPN protocol is not active on the selected server.');
        abort_if(VpnAccount::where('vpn_server_id', $server->id)->where('identifier', $data['identifier'])
            ->whereNotIn('status', ['revoked', 'expired'])->exists(), 422, 'This VPN username is already in use.');
        abort_if(VpnOrder::where('vpn_server_id', $server->id)->where('identifier', $data['identifier'])
            ->whereIn('status', ['pending_payment', 'payment_submitted', 'paid'])->exists(), 422, 'This VPN username is already reserved.');
        abort_if(($data['remote_service'] ?? null) === 'mikrotik' && ! empty($data['remote_device_ip']), 422,
            'MikroTik Winbox uses the VPN client router itself; do not enter a separate device IP.');

        $order = DB::transaction(function () use ($request, $data, $plan, $payment) {
            $order = VpnOrder::create([
                'user_id' => $request->user()->id, 'plan_id' => $plan->id, 'vpn_server_id' => $data['vpn_server_id'],
                'type' => 'new', 'status' => 'pending_payment', 'protocol' => $data['protocol'], 'identifier' => $data['identifier'],
                'remote_target_port' => $data['remote_target_port'] ?? null,
                'remote_service' => $data['remote_service'] ?? null,
                'client_device_type' => $data['client_device_type'] ?? null,
                'remote_device_ip' => $data['remote_device_ip'] ?? null,
                'remote_allowed_cidr' => $data['remote_allowed_cidr'] ?? null,
            // The field is encrypted at rest and is cleared on approval. For
            // WireGuard it temporarily carries the non-secret peer public key.
            'encrypted_client_password' => Crypt::encryptString($data['protocol'] === 'wireguard' ? $data['wireguard_public_key'] : $data['client_password']),
                'amount_cents' => $plan->price_cents, 'currency' => $plan->currency,
                'plan_name' => $plan->name, 'duration_days' => $plan->duration_days,
                'payment_due_at' => now()->addDays(3),
                'payment_bank_name' => $payment->bank_name, 'payment_account_name' => $payment->account_name,
                'payment_account_number' => $payment->account_number, 'payment_instructions' => $payment->instructions,
            ]);
            $order->update(['invoice_number' => 'VV-'.now()->format('Ymd').'-'.str_pad((string) $order->id, 6, '0', STR_PAD_LEFT)]);

            return $order;
        });

        return response()->json($order->load('server:id,name'), 201);
    }

    public function renew(Request $request, VpnAccount $account)
    {
        abort_unless($account->user_id === $request->user()->id, 404);
        abort_unless($account->status === 'active' && $account->plan?->is_active, 409, 'Only active services with an active plan can be renewed.');
        abort_if(VpnOrder::where('vpn_account_id', $account->id)->whereIn('status', ['pending_payment', 'payment_submitted'])->exists(), 409, 'A renewal invoice is already pending.');
        $payment = BillingSetting::first();
        abort_unless($payment?->bank_name && $payment?->account_name && $payment?->account_number, 409, 'Payment instructions are not configured yet.');
        $plan = $account->plan;
        $order = VpnOrder::create([
            'user_id' => $request->user()->id, 'plan_id' => $plan->id, 'vpn_server_id' => $account->vpn_server_id,
            'vpn_account_id' => $account->id, 'type' => 'renewal', 'status' => 'pending_payment', 'protocol' => $account->protocol,
            'identifier' => $account->identifier, 'amount_cents' => $plan->price_cents,
            'currency' => $plan->currency, 'plan_name' => $plan->name,
            'duration_days' => $plan->duration_days, 'payment_due_at' => now()->addDays(3),
            'payment_bank_name' => $payment->bank_name, 'payment_account_name' => $payment->account_name,
            'payment_account_number' => $payment->account_number, 'payment_instructions' => $payment->instructions,
        ]);
        $order->update(['invoice_number' => 'VV-'.now()->format('Ymd').'-'.str_pad((string) $order->id, 6, '0', STR_PAD_LEFT)]);

        return response()->json($order->load('server:id,name'), 201);
    }

    public function submitPayment(Request $request, VpnOrder $order)
    {
        abort_unless($order->user_id === $request->user()->id, 404);
        $data = $request->validate([
            'payment_reference' => ['required', 'string', 'min:3', 'max:150'],
            'payment_note' => ['nullable', 'string', 'max:1000'],
        ]);
        abort_unless($order->status === 'pending_payment' && $order->payment_due_at->isFuture(), 409, 'This invoice is no longer awaiting payment.');
        $order->update($data + ['status' => 'payment_submitted', 'submitted_at' => now()]);

        return $order->fresh();
    }

    public function cancel(Request $request, VpnOrder $order)
    {
        abort_unless($order->user_id === $request->user()->id, 404);
        abort_unless($order->status === 'pending_payment', 409, 'Only unpaid invoices can be cancelled.');
        $order->update(['status' => 'cancelled', 'encrypted_client_password' => null]);

        return $order->fresh();
    }

    public function adminOrders(Request $request)
    {
        return VpnOrder::query()->with(['user:id,name,email', 'server:id,name', 'account:id,status,expires_at'])
            ->when($request->string('status')->toString(), fn ($query, $status) => $query->where('status', $status))
            ->latest()->paginate(30);
    }

    public function reject(Request $request, VpnOrder $order)
    {
        $data = $request->validate(['admin_note' => ['required', 'string', 'max:1000']]);
        abort_unless($order->status === 'payment_submitted', 409, 'Only submitted payments can be rejected.');
        $order->update(['status' => 'pending_payment', 'payment_reference' => null, 'payment_note' => null, 'submitted_at' => null, 'admin_note' => $data['admin_note']]);
        OperationLog::create([
            'user_id' => $request->user()->id, 'vpn_server_id' => $order->vpn_server_id,
            'vpn_account_id' => $order->vpn_account_id, 'operation' => 'reject_payment',
            'status' => 'success', 'message' => $order->invoice_number.': '.$data['admin_note'],
            'completed_at' => now(),
        ]);

        return $order->fresh();
    }

    public function confirm(Request $request, VpnOrder $order)
    {
        $accountId = DB::transaction(function () use ($order, $request) {
            $locked = VpnOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === 'payment_submitted', 409, 'This payment is not awaiting approval.');
            if ($locked->type === 'renewal') {
                $account = VpnAccount::whereKey($locked->vpn_account_id)->lockForUpdate()->firstOrFail();
                abort_unless($account->user_id === $locked->user_id && $account->status === 'active', 409, 'This service can no longer be renewed.');
                $startsAt = $account->expires_at && $account->expires_at->isFuture() ? $account->expires_at->copy() : now();
                $expiresAt = $startsAt->copy()->addDays($locked->duration_days);
                $account->update(['expires_at' => $expiresAt]);
                Subscription::create([
                    'vpn_account_id' => $account->id, 'plan_id' => $locked->plan_id,
                    'starts_at' => $startsAt, 'expires_at' => $expiresAt, 'status' => 'active',
                    'amount_cents' => $locked->amount_cents, 'currency' => $locked->currency,
                    'change_type' => 'renewal', 'notes' => $locked->invoice_number,
                ]);
                $accountId = null;
            } else {
                abort_if(VpnAccount::where('vpn_server_id', $locked->vpn_server_id)->where('protocol', $locked->protocol)->where('identifier', $locked->identifier)->exists(), 409, 'The VPN username is already in use.');
                $plan = Plan::findOrFail($locked->plan_id);
                $adapterPayload = null;
                $provisioningSecret = $locked->encrypted_client_password;
                $allocatedIp = null;
                if ($locked->protocol === 'wireguard') {
                    $peerKey = $locked->encrypted_client_password ? Crypt::decryptString($locked->encrypted_client_password) : '';
                    abort_unless(preg_match('/^[A-Za-z0-9+\\/]{43}=$/', $peerKey), 422, 'The WireGuard public key for this order is invalid.');
                    $server = VpnServer::findOrFail($locked->vpn_server_id);
                    $allocatedIp = $this->nextWireGuardIp($server);
                    $adapterPayload = ['wireguard_public_key' => $peerKey];
                    $provisioningSecret = null;
                }
                $account = VpnAccount::create([
                    'uuid' => (string) Str::uuid(), 'idempotency_key' => (string) Str::uuid(),
                    'user_id' => $locked->user_id, 'vpn_server_id' => $locked->vpn_server_id,
                    'plan_id' => $locked->plan_id, 'protocol' => $locked->protocol,
                    'identifier' => $locked->identifier, 'status' => 'pending',
                    'remote_target_port' => $locked->remote_target_port,
                    'remote_service' => $locked->remote_service,
                    'client_device_type' => $locked->client_device_type,
                    'remote_device_ip' => $locked->remote_device_ip,
                    'remote_allowed_cidr' => $locked->remote_allowed_cidr,
                    'starts_at' => null, 'expires_at' => null, 'allocated_ip' => $allocatedIp,
                    'device_limit' => $plan->connection_limit, 'bandwidth_mbps' => $plan->bandwidth_mbps,
                    'data_quota_bytes' => $plan->data_quota_bytes,
                    'adapter_payload' => $adapterPayload, 'encrypted_provisioning_secret' => $provisioningSecret,
                ]);
                Subscription::create([
                    'vpn_account_id' => $account->id, 'plan_id' => $locked->plan_id,
                    'starts_at' => now(), 'expires_at' => now()->addDays($locked->duration_days),
                    'status' => 'pending_activation', 'amount_cents' => $locked->amount_cents,
                    'currency' => $locked->currency, 'notes' => $locked->invoice_number,
                ]);
                $accountId = $account->id;
            }
            $locked->update(['status' => 'paid', 'paid_at' => now(), 'vpn_account_id' => $account->id, 'encrypted_client_password' => null]);
            OperationLog::create([
                'user_id' => $request->user()->id, 'vpn_server_id' => $locked->vpn_server_id,
                'vpn_account_id' => $account->id, 'operation' => 'confirm_payment',
                'status' => 'success', 'message' => $locked->invoice_number,
                'details' => ['amount_cents' => $locked->amount_cents, 'currency' => $locked->currency, 'payment_reference' => $locked->payment_reference],
                'completed_at' => now(),
            ]);

            return $accountId;
        });

        if ($accountId) {
            ProvisionVpnAccount::dispatch($accountId, $request->user()->id);
        }

        return $order->fresh()->load('account:id,status,expires_at');
    }

    private function nextWireGuardIp(VpnServer $server): string
    {
        $cidr = data_get($server->capabilities, 'wireguard_peer_cidr');
        if (! is_string($cidr) || ! preg_match('/^((?:[0-9]{1,3}\.){3}[0-9]{1,3})\/(\d{1,2})$/', $cidr, $parts)
            || (int) $parts[2] !== 24 || ! filter_var($parts[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            throw ValidationException::withMessages(['protocol' => 'This WireGuard server has no valid /24 peer network configured.']);
        }
        $network = ip2long($parts[1]);
        $used = VpnAccount::query()->where('vpn_server_id', $server->id)->whereNotIn('status', ['revoked', 'expired'])
            ->whereNotNull('allocated_ip')->pluck('allocated_ip')->map(fn ($ip) => ip2long($ip))->filter()->flip();
        for ($offset = 10; $offset <= 254; $offset++) {
            $candidate = long2ip($network + $offset);
            if (! $used->has($network + $offset)) {
                return $candidate;
            }
        }
        throw ValidationException::withMessages(['protocol' => 'The WireGuard peer address pool is full.']);
    }

}
