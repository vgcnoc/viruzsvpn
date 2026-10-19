<?php

require '/var/www/viruzsvpn/backend/vendor/autoload.php';
$app = require '/var/www/viruzsvpn/backend/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$environment = parse_ini_file('/etc/virvpn-openvpn/agent.env', false, INI_SCANNER_RAW);
$secret = $environment['VPN_AGENT_SHARED_SECRET'] ?? '';
if (strlen($secret) < 32) {
    throw new RuntimeException('The local agent secret is unavailable.');
}
$server = App\Models\VpnServer::firstOrNew([
    'adapter' => 'linux-openvpn', 'hostname' => '139.190.96.74', 'port' => 1196,
]);
$server->fill([
    'name' => 'VPS VIRUZS - OpenVPN', 'location' => 'VPS VIRUZS',
    'capabilities' => ['protocols' => ['openvpn']], 'is_demo' => false, 'tls_verify' => true,
    'encrypted_credentials' => Illuminate\Support\Facades\Crypt::encryptString(json_encode([
        'agent_shared_secret' => $secret, 'agent_port' => 9444,
    ], JSON_THROW_ON_ERROR)),
]);
$result = (new App\Services\Servers\LinuxOpenVpnAdapter)->testConnection($server);
if (($result['status'] ?? '') !== 'online') {
    throw new RuntimeException('The new OpenVPN agent is not ready.');
}
$server->fill(['status' => 'online', 'vpn_status' => 'online', 'last_checked_at' => now(),
    'last_latency_ms' => $result['latency_ms'], 'software_version' => $result['data']['version'] ?? null]);
$server->save();
App\Models\OperationLog::create(['vpn_server_id' => $server->id, 'operation' => 'register_linux_openvpn',
    'status' => 'success', 'message' => 'Dedicated Linux OpenVPN agent verified and registered.', 'completed_at' => now()]);
echo json_encode(['server_id' => $server->id, 'name' => $server->name, 'endpoint' => $server->hostname.':'.$server->port,
    'status' => $server->status], JSON_PRETTY_PRINT).PHP_EOL;
