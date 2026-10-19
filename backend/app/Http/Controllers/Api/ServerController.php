<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OperationLog;
use App\Models\VpnServer;
use App\Services\Servers\DriverFactory;
use App\Services\Servers\MikroTikAdapter;
use App\Services\Servers\Ipv4Range;
use App\Services\Servers\ProtocolRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ServerController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', VpnServer::class);
        $servers = VpnServer::query()->withCount('accounts')
            ->when($request->string('search')->toString(), fn ($q, $search) => $q->where('name', 'like', "%$search%"))
            ->latest()->paginate($request->integer('per_page', 15));

        return response()->json($servers);
    }

    public function store(Request $request)
    {
        $this->authorize('create', VpnServer::class);
        $data = $this->validated($request);
        if (isset($data['encrypted_credentials'])) {
            $credentials = json_decode(Crypt::decryptString($data['encrypted_credentials']), true, 512, JSON_THROW_ON_ERROR);
            if ($credentials['routeros_remote_auto_enabled'] ?? false) {
                throw ValidationException::withMessages(['credentials.routeros_remote_auto_enabled' => 'Register the server and paste its setup script before enabling automatic remote access.']);
            }
        }
        $server = VpnServer::create($data);

        return response()->json($server, 201);
    }

    public function show(VpnServer $server)
    {
        $this->authorize('view', $server);

        return $server->loadCount('accounts');
    }

    public function update(Request $request, VpnServer $server)
    {
        $this->authorize('update', $server);
        $data = $this->validated($request, true, $server);
        if (isset($data['encrypted_credentials'])) {
            $next = json_decode(Crypt::decryptString($data['encrypted_credentials']), true, 512, JSON_THROW_ON_ERROR);
            if ($next['routeros_remote_auto_enabled'] ?? false) {
                $candidate = clone $server;
                $candidate->fill($data);
                $ready = app(MikroTikAdapter::class)->remotePrerequisites($candidate);
                if (($ready['status'] ?? '') !== 'success') {
                    throw ValidationException::withMessages(['credentials.routeros_remote_auto_enabled' => $ready['message'] ?? 'The CHR setup is not ready.']);
                }
            }
        }
        $server->update($data);

        return $server->fresh();
    }

    public function destroy(VpnServer $server)
    {
        $this->authorize('delete', $server);
        if ($server->accounts()->exists()) {
            return response()->json(['message' => 'A server with VPN accounts cannot be deleted. Disable it instead.'], 409);
        }
        $server->delete();

        return response()->noContent();
    }

    public function test(VpnServer $server, DriverFactory $drivers)
    {
        $this->authorize('update', $server);
        $result = $drivers->for($server)->testConnection($server);
        $server->update([
            'status' => $result['status'] ?? 'unknown', 'last_checked_at' => now(),
            'last_latency_ms' => $result['latency_ms'] ?? null,
            'vpn_status' => $result['vpn_status'] ?? data_get($result, 'data.vpn_services.wireguard.status') ?? $server->vpn_status,
            'software_version' => data_get($result, 'data.version') ?? $server->software_version,
        ]);
        OperationLog::create(['user_id' => request()->user()->id, 'vpn_server_id' => $server->id, 'operation' => 'test_connection', 'status' => $result['status'] ?? 'unknown', 'message' => $result['message'] ?? null, 'details' => $result, 'completed_at' => now()]);

        return response()->json(['server' => $server->fresh(), 'result' => $result]);
    }

    public function capabilities(VpnServer $server)
    {
        $this->authorize('view', $server);

        return ['protocols' => ProtocolRegistry::supportedBy($server->capabilities ?? []), 'definitions' => ProtocolRegistry::DEFINITIONS];
    }

    public function remoteSettings(VpnServer $server)
    {
        $this->authorize('view', $server);
        $credentials = $server->encrypted_credentials
            ? json_decode(Crypt::decryptString($server->encrypted_credentials), true, 512, JSON_THROW_ON_ERROR) : [];

        return response()->json([
            'enabled' => (bool) ($credentials['routeros_remote_auto_enabled'] ?? false),
            'wan_interface' => $credentials['routeros_wan_interface'] ?? null,
            'vpn_gateway' => $credentials['routeros_vpn_gateway'] ?? null,
            'vpn_pool' => $credentials['routeros_vpn_pool'] ?? null,
            'remote_ip_pool' => $credentials['routeros_remote_ip_pool'] ?? null,
            'vpn_subnet' => $credentials['routeros_vpn_subnet'] ?? null,
            'ppp_profile' => $credentials['routeros_ppp_profile'] ?? null,
            'certificate_name' => $credentials['routeros_certificate'] ?? null,
        ]);
    }

    private function validated(Request $request, bool $updating = false, ?VpnServer $server = null): array
    {
        $rules = [
            'name' => [$updating ? 'sometimes' : 'required', 'string', 'max:100'],
            'adapter' => [$updating ? 'sometimes' : 'required', Rule::in(['demo', 'linux-agent', 'linux-l2tp', 'linux-openvpn', 'mikrotik-routeros'])],
            'hostname' => [$updating ? 'sometimes' : 'required', 'string', 'max:253'],
            'port' => [$updating ? 'sometimes' : 'required', 'integer', 'between:1,65535'],
            'location' => ['nullable', 'string', 'max:100'], 'description' => ['nullable', 'string', 'max:2000'],
            'tags' => ['nullable', 'array'], 'tls_verify' => ['boolean'], 'is_demo' => ['boolean'],
            'capabilities' => ['nullable', 'array'], 'credentials' => ['nullable', 'array'],
            'credentials.agent_shared_secret' => ['nullable', 'string', 'min:32', 'max:512'],
            'credentials.agent_port' => ['nullable', 'integer', 'between:1024,65535'],
            'credentials.l2tp_ipsec_psk' => ['nullable', 'string', 'min:16', 'max:255'],
            'credentials.routeros_username' => ['nullable', 'string', 'max:128'],
            'credentials.routeros_password' => ['nullable', 'string', 'max:512'],
            'credentials.routeros_api_ssl' => ['nullable', 'boolean'],
            'credentials.routeros_allowed_cidr' => ['nullable', 'string', 'max:18', 'regex:/^(?:[0-9]{1,3}\\.){3}[0-9]{1,3}\\/(?:[0-9]|[12][0-9]|3[0-2])$/'],
            'credentials.routeros_certificate' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]{1,64}$/'],
            'credentials.routeros_ppp_profile' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]{1,64}$/'],
            'credentials.routeros_l2tp_secret' => ['nullable', 'string', 'min:16', 'max:255'],
            'credentials.routeros_remote_auto_enabled' => ['nullable', 'boolean'],
            'credentials.routeros_wan_interface' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'],
            'credentials.routeros_vpn_gateway' => ['nullable', 'ipv4'],
            'credentials.routeros_vpn_pool' => ['nullable', 'string', 'regex:/^(?:[0-9]{1,3}\\.){3}[0-9]{1,3}-(?:[0-9]{1,3}\\.){3}[0-9]{1,3}$/'],
            'credentials.routeros_remote_ip_pool' => ['nullable', 'string', 'max:31'],
            'credentials.routeros_vpn_subnet' => ['nullable', 'string', 'max:18'],
        ];
        $data = $request->validate($rules);
        $adapter = $data['adapter'] ?? $server?->adapter;
        $requiresNewCredentials = ! $updating || ($server && array_key_exists('adapter', $data) && $adapter !== $server->adapter);
        $updatesRouterOsCredentials = $adapter === 'mikrotik-routeros' && array_key_exists('credentials', $data);
        $existingCredentials = $server?->encrypted_credentials
            ? json_decode(Crypt::decryptString($server->encrypted_credentials), true, 512, JSON_THROW_ON_ERROR)
            : [];
        $effectiveCredentials = array_replace($existingCredentials, $data['credentials'] ?? []);
        if ($adapter === 'mikrotik-routeros' && ($effectiveCredentials['routeros_remote_auto_enabled'] ?? false)) {
            foreach (['routeros_wan_interface', 'routeros_vpn_gateway', 'routeros_vpn_pool', 'routeros_remote_ip_pool', 'routeros_vpn_subnet'] as $field) {
                if (empty($effectiveCredentials[$field])) {
                    throw ValidationException::withMessages(['credentials.'.$field => 'Required for automatic remote access.']);
                }
            }
            try {
                [$start, $end] = Ipv4Range::parse($effectiveCredentials['routeros_remote_ip_pool']);
                $valid = $end - $start <= 65535
                    && Ipv4Range::withinSubnet($effectiveCredentials['routeros_remote_ip_pool'], $effectiveCredentials['routeros_vpn_subnet'])
                    && Ipv4Range::withinSubnet($effectiveCredentials['routeros_vpn_pool'], $effectiveCredentials['routeros_vpn_subnet'])
                    && ! Ipv4Range::overlaps($effectiveCredentials['routeros_remote_ip_pool'], $effectiveCredentials['routeros_vpn_pool'])
                    && ! Ipv4Range::overlaps($effectiveCredentials['routeros_remote_ip_pool'], $effectiveCredentials['routeros_vpn_gateway']);
            } catch (\InvalidArgumentException) {
                $valid = false;
            }
            if (! $valid) {
                throw ValidationException::withMessages(['credentials.routeros_remote_ip_pool' => 'Use a static range inside the VPN subnet, separate from the dynamic pool and gateway.']);
            }
        }
        $requiresRouterOsBootstrap = $requiresNewCredentials || ($updatesRouterOsCredentials && empty($existingCredentials));
        if ($requiresNewCredentials && in_array($adapter, ['linux-agent', 'linux-l2tp', 'linux-openvpn'], true) && empty($data['credentials']['agent_shared_secret'])) {
            throw ValidationException::withMessages(['credentials.agent_shared_secret' => 'A Linux agent shared secret is required.']);
        }
        if ($requiresRouterOsBootstrap && $adapter === 'mikrotik-routeros' && (empty($effectiveCredentials['routeros_username']) || empty($effectiveCredentials['routeros_password']))) {
            throw ValidationException::withMessages(['credentials.routeros_username' => 'RouterOS API username and password are required.']);
        }
        if ($requiresRouterOsBootstrap && $adapter === 'mikrotik-routeros' && empty($effectiveCredentials['routeros_allowed_cidr'])) {
            throw ValidationException::withMessages(['credentials.routeros_allowed_cidr' => 'A single IPv4 CIDR allowed to reach the RouterOS API is required.']);
        }
        if ($requiresRouterOsBootstrap && $adapter === 'mikrotik-routeros') {
            $allowedAddress = explode('/', (string) $effectiveCredentials['routeros_allowed_cidr'])[0];
            $hostname = $data['hostname'] ?? $server?->hostname;
            $serverAddresses = filter_var($hostname, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
                ? [$hostname]
                : (gethostbynamel((string) $hostname) ?: []);

            if (in_array($allowedAddress, $serverAddresses, true)) {
                throw ValidationException::withMessages(['credentials.routeros_allowed_cidr' => 'This is the RouterOS server address. Enter the public IP of the machine running VPNOPS instead.']);
            }
        }
        if ($requiresRouterOsBootstrap && $adapter === 'mikrotik-routeros' && ($effectiveCredentials['routeros_api_ssl'] ?? true) && empty($effectiveCredentials['routeros_certificate'])) {
            throw ValidationException::withMessages(['credentials.routeros_certificate' => 'The RouterOS certificate name is required when API-SSL is enabled.']);
        }
        if ($adapter === 'mikrotik-routeros' && ! ($effectiveCredentials['routeros_api_ssl'] ?? true) && ($data['tls_verify'] ?? $server?->tls_verify ?? true)) {
            throw ValidationException::withMessages(['tls_verify' => 'Disable TLS verification only when deliberately using plain RouterOS API on a trusted private network.']);
        }
        if (array_key_exists('credentials', $data)) {
            $data['encrypted_credentials'] = Crypt::encryptString(json_encode($effectiveCredentials, JSON_THROW_ON_ERROR));
            unset($data['credentials']);
        }

        return $data;
    }
}
