<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProvisionVpnAccount;
use App\Models\OperationLog;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\VpnAccount;
use App\Models\VpnServer;
use App\Services\Servers\DriverFactory;
use App\Services\Servers\ProtocolRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class VpnAccountController extends Controller
{
    public function index(Request $request)
    {
        return VpnAccount::query()->with(['server:id,name,is_demo', 'plan:id,name'])
            ->when($request->string('search')->toString(), fn ($q, $search) => $q->where('identifier', 'like', "%$search%"))
            ->when($request->string('status')->toString(), fn ($q, $status) => $q->where('status', $status))
            ->when($request->integer('server_id'), fn ($q, $server) => $q->where('vpn_server_id', $server))
            ->latest()->paginate($request->integer('per_page', 20));
    }

    public function store(Request $request)
    {
        $key = $request->header('Idempotency-Key');
        if ($key && ($existing = VpnAccount::where('idempotency_key', $key)->first())) {
            return response()->json($existing->load(['server', 'plan']));
        }

        $data = $request->validate([
            'vpn_server_id' => ['required', 'integer', 'exists:vpn_servers,id'], 'plan_id' => ['nullable', 'integer', 'exists:plans,id'],
            'protocol' => ['required', Rule::in(array_keys(ProtocolRegistry::DEFINITIONS))],
            'identifier' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9._-]+$/'],
            'allocated_ip' => ['nullable', 'ip'], 'starts_at' => ['nullable', 'date'], 'expires_at' => ['nullable', 'date', 'after:starts_at'],
            'device_limit' => ['nullable', 'integer', 'between:1,100'], 'bandwidth_mbps' => ['nullable', 'integer', 'min:1'],
            'data_quota_bytes' => ['nullable', 'integer', 'min:0'],
            'credentials' => ['nullable', 'array'],
            'credentials.password' => ['nullable', 'string', 'min:8', 'max:255'],
            'wireguard_public_key' => ['nullable', 'string', 'regex:/^[A-Za-z0-9+\\/]{43}=$/'],
        ]);
        $server = VpnServer::findOrFail($data['vpn_server_id']);
        abort_unless(in_array($data['protocol'], ProtocolRegistry::supportedBy($server->capabilities ?? []), true), 422, 'The selected server does not support this protocol.');
        $plan = isset($data['plan_id']) ? Plan::findOrFail($data['plan_id']) : null;
        abort_if($plan && (! $plan->is_active || ! in_array($data['protocol'], $plan->allowed_protocols, true)), 422, 'The selected plan does not support this protocol.');
        abort_if($plan && $plan->allowed_server_ids && ! in_array($server->id, $plan->allowed_server_ids, true), 422, 'The selected plan does not allow this server.');
        if ($server->adapter === 'linux-agent' && $data['protocol'] === 'wireguard') {
            abort_unless(! empty($data['wireguard_public_key']) && ! empty($data['allocated_ip']), 422, 'WireGuard provisioning requires a peer public key and an allocated IPv4 address.');
        }
        if ($server->adapter === 'mikrotik-routeros' && in_array($data['protocol'], ['ppp', 'l2tp-ipsec', 'sstp', 'pptp', 'openvpn'], true)) {
            abort_unless(! empty($data['credentials']['password']), 422, 'PPP-based RouterOS provisioning requires a client password.');
        }

        $startsAt = isset($data['starts_at']) ? Carbon::parse($data['starts_at']) : now();
        $data['uuid'] = (string) Str::uuid();
        $data['idempotency_key'] = $key ?: (string) Str::uuid();
        $data['starts_at'] = $startsAt;
        $data['expires_at'] = $data['expires_at'] ?? ($plan ? $startsAt->copy()->addDays($plan->duration_days) : null);
        $data['device_limit'] ??= $plan?->connection_limit;
        $data['bandwidth_mbps'] ??= $plan?->bandwidth_mbps;
        $data['data_quota_bytes'] ??= $plan?->data_quota_bytes;
        $data['status'] = 'pending';
        if (! empty($data['wireguard_public_key'])) {
            $data['adapter_payload'] = ['wireguard_public_key' => $data['wireguard_public_key']];
        }
        if (! empty($data['credentials']['password'])) {
            $data['encrypted_provisioning_secret'] = Crypt::encryptString($data['credentials']['password']);
        }
        unset($data['credentials'], $data['wireguard_public_key']);
        $account = VpnAccount::create($data);
        if ($plan && $account->expires_at) {
            Subscription::create(['vpn_account_id' => $account->id, 'plan_id' => $plan->id, 'starts_at' => $startsAt, 'expires_at' => $account->expires_at, 'status' => 'active', 'amount_cents' => $plan->price_cents, 'currency' => $plan->currency]);
        }
        ProvisionVpnAccount::dispatch($account->id, $request->user()->id);

        return response()->json($account->fresh(['server', 'plan']), 202);
    }

    public function show(VpnAccount $account)
    {
        return $account->load(['server', 'plan', 'subscriptions.plan', 'operationLogs' => fn ($q) => $q->latest()]);
    }

    public function update(Request $request, VpnAccount $account, DriverFactory $drivers)
    {
        $data = $request->validate(['allocated_ip' => ['nullable', 'ip'], 'device_limit' => ['nullable', 'integer', 'between:1,100'], 'bandwidth_mbps' => ['nullable', 'integer', 'min:1'], 'data_quota_bytes' => ['nullable', 'integer', 'min:0']]);
        if (array_key_exists('allocated_ip', $data) && $data['allocated_ip'] !== $account->allocated_ip) {
            abort_unless(in_array($account->status, ['active', 'suspended'], true), 409, 'Only active or suspended accounts can change a server-side IP assignment.');
            $account->fill(['allocated_ip' => $data['allocated_ip']]);
            $result = $drivers->for($account->server)->updateAccount($account->server, $account);
            if (! ($result['supported'] ?? false)) {
                return response()->json(['message' => $result['message'] ?? 'The server does not support IP updates.', 'result' => $result], 409);
            }
            if (! in_array($result['status'] ?? '', ['success', 'demo'], true)) {
                return response()->json(['message' => $result['message'] ?? 'The server IP update failed.', 'result' => $result], 502);
            }
        }
        $account->update($data);

        return $account->fresh(['server', 'plan']);
    }

    public function suspend(Request $request, VpnAccount $account, DriverFactory $drivers)
    {
        return $this->changeStatus($request, $account, $drivers, 'suspend');
    }

    public function activate(Request $request, VpnAccount $account, DriverFactory $drivers)
    {
        return $this->changeStatus($request, $account, $drivers, 'activate');
    }

    public function revoke(Request $request, VpnAccount $account, DriverFactory $drivers)
    {
        return $this->changeStatus($request, $account, $drivers, 'revoke');
    }

    public function destroy(Request $request, VpnAccount $account, DriverFactory $drivers)
    {
        return $this->changeStatus($request, $account, $drivers, 'revoke');
    }

    public function retry(Request $request, VpnAccount $account)
    {
        abort_unless($account->status === 'failed', 409, 'Only failed provisioning requests can be retried.');
        $account->update(['status' => 'pending', 'provisioning_error' => null]);
        ProvisionVpnAccount::dispatch($account->id, $request->user()->id);

        return response()->json($account->fresh(['server', 'plan']), 202);
    }

    public function extend(Request $request, VpnAccount $account)
    {
        abort_unless(in_array($account->status, ['active', 'suspended'], true), 409, 'Only active or suspended accounts can be extended. Create a new account after expiry or revocation.');
        $data = $request->validate(['expires_at' => ['required', 'date', 'after:now'], 'notes' => ['nullable', 'string', 'max:1000']]);
        $account->update(['expires_at' => $data['expires_at'], 'status' => 'active']);
        if ($account->plan) {
            Subscription::create(['vpn_account_id' => $account->id, 'plan_id' => $account->plan_id, 'starts_at' => now(), 'expires_at' => $data['expires_at'], 'status' => 'active', 'amount_cents' => $account->plan->price_cents, 'currency' => $account->plan->currency, 'change_type' => 'renewal', 'notes' => $data['notes'] ?? null]);
        }
        OperationLog::create(['user_id' => $request->user()->id, 'vpn_server_id' => $account->vpn_server_id, 'vpn_account_id' => $account->id, 'operation' => 'extend_account', 'status' => 'success', 'message' => 'Expiry updated.', 'completed_at' => now()]);

        return $account->fresh(['server', 'plan']);
    }

    public function export()
    {
        $rows = VpnAccount::with(['server:id,name', 'plan:id,name'])->cursor();

        return response()->streamDownload(function () use ($rows) {
            $file = fopen('php://output', 'w');
            fputcsv($file, ['identifier', 'server', 'protocol', 'plan', 'status', 'expires_at']);
            foreach ($rows as $account) {
                fputcsv($file, [$account->identifier, $account->server->name, $account->protocol, $account->plan?->name, $account->status, $account->expires_at?->toDateString()]);
            }
            fclose($file);
        }, 'vpn-accounts.csv', ['Content-Type' => 'text/csv']);
    }

    private function changeStatus(Request $request, VpnAccount $account, DriverFactory $drivers, string $action)
    {
        if ($action === 'suspend') {
            abort_unless($account->status === 'active', 409, 'Only active accounts can be suspended.');
        }
        if ($action === 'activate') {
            abort_unless($account->status === 'suspended', 409, 'Only suspended accounts can be activated.');
        }
        if ($action === 'revoke') {
            abort_unless(! in_array($account->status, ['revoked', 'expired'], true), 409, 'This account is already inactive.');
        }
        $driver = $drivers->for($account->server);
        $result = match ($action) {
            'suspend' => $driver->suspendAccount($account->server, $account),
            'activate' => $driver->activateAccount($account->server, $account),
            default => $driver->deleteAccount($account->server, $account),
        };
        if (! ($result['supported'] ?? false)) {
            return response()->json(['message' => $result['message'] ?? 'Operation unsupported.', 'result' => $result], 409);
        }
        if (! in_array($result['status'] ?? '', ['success', 'demo'], true)) {
            OperationLog::create([
                'user_id' => $request->user()->id, 'vpn_server_id' => $account->vpn_server_id,
                'vpn_account_id' => $account->id, 'operation' => $action.'_account', 'status' => 'failed',
                'message' => $result['message'] ?? 'Server adapter operation failed.', 'details' => $result, 'completed_at' => now(),
            ]);

            return response()->json(['message' => $result['message'] ?? 'Server adapter operation failed.', 'result' => $result], 502);
        }
        $status = match ($action) {
            'suspend' => 'suspended', 'activate' => 'active', default => 'revoked'
        };
        $account->update(['status' => $status]);
        OperationLog::create(['user_id' => $request->user()->id, 'vpn_server_id' => $account->vpn_server_id, 'vpn_account_id' => $account->id, 'operation' => $action.'_account', 'status' => $result['status'] ?? 'success', 'details' => $result, 'completed_at' => now()]);

        return $account->fresh(['server', 'plan']);
    }
}
