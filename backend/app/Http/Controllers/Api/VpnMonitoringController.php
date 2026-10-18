<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OperationLog;
use App\Models\VpnAccount;
use App\Models\VpnServer;
use App\Services\Servers\DriverFactory;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Throwable;

class VpnMonitoringController extends Controller
{
    public function index(Request $request, DriverFactory $drivers)
    {
        $input = $request->validate([
            'server_id' => ['nullable', 'integer', 'exists:vpn_servers,id'],
            'state' => ['nullable', 'in:all,online,offline,unknown,active,suspended,expiring,expired,pending,provisioning,failed,revoked'],
            'search' => ['nullable', 'string', 'max:128'],
            'page' => ['nullable', 'integer', 'min:1'],
            'unmanaged_page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:10,100'],
        ]);
        $serverOptions = VpnServer::query()->orderBy('name')->get(['id', 'name']);
        $servers = VpnServer::query()->when($input['server_id'] ?? null, fn ($q, $id) => $q->whereKey($id))->orderBy('name')->get();
        $serverChecks = [];
        $sessionsByServer = [];
        foreach ($servers as $server) {
            try {
                $result = $drivers->for($server)->getActiveSessions($server);
            } catch (Throwable) {
                $result = ['supported' => true, 'status' => 'offline'];
            }
            $available = ($result['supported'] ?? false) && ($result['status'] ?? '') === 'success';
            $sessionsByServer[$server->id] = $available ? ($result['data'] ?? []) : [];
            $serverChecks[] = [
                'id' => $server->id, 'name' => $server->name, 'available' => $available,
                'status' => $available ? 'online' : (($result['supported'] ?? false) ? 'unavailable' : 'unsupported'),
                'message' => $available ? null : ($result['message'] ?? 'Sesi langsung tidak tersedia pada adapter ini.'),
            ];
        }

        $availableIds = collect($serverChecks)->where('available', true)->pluck('id')->all();
        $accounts = VpnAccount::query()->with(['server:id,name,adapter', 'plan:id,name', 'user:id,name,email'])
            ->whereIn('vpn_server_id', $servers->pluck('id'))->orderByDesc('id')->get();
        $rows = $accounts->map(function (VpnAccount $account) use ($availableIds, $sessionsByServer) {
            $expectedService = match ($account->protocol) {
                'l2tp-ipsec' => 'l2tp', 'openvpn' => 'ovpn', 'sstp' => 'sstp', 'pptp' => 'pptp',
                'ppp' => 'any', default => null,
            };
            $sessions = collect($sessionsByServer[$account->vpn_server_id] ?? [])
                ->filter(fn ($session) => $expectedService !== null
                    && ($session['identifier'] ?? null) === $account->identifier
                    && ($expectedService === 'any' || strtolower((string) ($session['service'] ?? '')) === $expectedService))
                ->values()->all();
            $connection = ! in_array($account->vpn_server_id, $availableIds, true) || $expectedService === null
                ? 'unknown' : (count($sessions) > 0 ? 'online' : 'offline');

            return [
                'id' => $account->id, 'identifier' => $account->identifier, 'protocol' => $account->protocol,
                'status' => $account->status, 'connection' => $connection, 'sessions' => $sessions,
                'server' => $account->server, 'plan' => $account->plan, 'user' => $account->user,
                'expires_at' => $account->expires_at?->toIso8601String(), 'allocated_ip' => $account->allocated_ip,
            ];
        });
        $matchedSessionKeys = $rows->flatMap(fn ($row) => collect($row['sessions'])
            ->map(fn ($session) => $row['server']['id'].':'.($session['id'] ?? '')))->flip();
        $serverNames = $servers->pluck('name', 'id');
        $unmanaged = collect($sessionsByServer)->flatMap(fn ($sessions, $serverId) => collect($sessions)
            ->filter(fn ($session) => ! $matchedSessionKeys->has($serverId.':'.($session['id'] ?? '')))
            ->map(fn ($session) => array_merge($session, ['server_id' => $serverId, 'server_name' => $serverNames[$serverId] ?? 'Server'])))->values();
        $summary = [
            'total' => $rows->count(),
            'online' => $rows->where('connection', 'online')->count(),
            'sessions' => $rows->sum(fn ($row) => count($row['sessions'])),
            'unmanaged_sessions' => $unmanaged->count(),
            'offline' => $rows->where('connection', 'offline')->where('status', 'active')->count(),
            'unknown' => $rows->where('connection', 'unknown')->count(),
            'suspended' => $rows->where('status', 'suspended')->count(),
            'expiring_soon' => $rows->filter(fn ($row) => $row['status'] === 'active' && $row['expires_at']
                && Carbon::parse($row['expires_at'])->between(now(), now()->addDays(7)))->count(),
        ];
        $state = $input['state'] ?? 'all';
        if ($state !== 'all') {
            $rows = $rows->filter(fn ($row) => in_array($state, ['online', 'offline', 'unknown'], true)
                ? $row['connection'] === $state : $row['status'] === $state);
        }
        if ($search = trim($input['search'] ?? '')) {
            $needle = mb_strtolower($search);
            $rows = $rows->filter(fn ($row) => str_contains(mb_strtolower($row['identifier']), $needle)
                || str_contains(mb_strtolower($row['user']['name'] ?? ''), $needle)
                || str_contains(mb_strtolower($row['user']['email'] ?? ''), $needle));
        }
        $page = (int) ($input['page'] ?? 1);
        $perPage = (int) ($input['per_page'] ?? 20);
        $rows = $rows->values();
        $paginator = new LengthAwarePaginator($rows->forPage($page, $perPage)->values(), $rows->count(), $perPage, $page, [
            'path' => $request->url(), 'query' => $request->query(),
        ]);
        $unmanagedPage = (int) ($input['unmanaged_page'] ?? 1);
        $unmanagedPaginator = new LengthAwarePaginator($unmanaged->forPage($unmanagedPage, 20)->values(), $unmanaged->count(), 20, $unmanagedPage);

        return response()->json(['summary' => $summary, 'servers' => $serverChecks, 'server_options' => $serverOptions, 'accounts' => $paginator, 'unmanaged_sessions' => $unmanagedPaginator, 'checked_at' => now()->toIso8601String()]);
    }

    public function disconnect(Request $request, VpnAccount $account, DriverFactory $drivers)
    {
        $input = $request->validate(['session_id' => ['required', 'string', 'regex:/^\*[A-Za-z0-9]{1,32}$/']]);
        $result = $drivers->for($account->server)->disconnectSession($account->server, $account, $input['session_id']);
        $success = ($result['supported'] ?? false) && ($result['status'] ?? '') === 'success';
        OperationLog::create([
            'user_id' => $request->user()->id, 'vpn_server_id' => $account->vpn_server_id,
            'vpn_account_id' => $account->id, 'operation' => 'disconnect_session',
            'status' => $success ? 'success' : 'failed', 'message' => $result['message'] ?? null,
            'completed_at' => now(),
        ]);

        return response()->json(['message' => $result['message'] ?? 'Aksi sesi selesai.'], $success ? 200 : (($result['supported'] ?? false) ? 409 : 422));
    }
}
