<?php

/** Register a remote, tunnel-reachable OpenVPN agent without writing its secret to disk. */
require '/var/www/viruzsvpn/backend/vendor/autoload.php';
$app = require '/var/www/viruzsvpn/backend/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

[$script, $hostname, $vpnPort, $agentPort, $name] = array_pad($argv, 5, null);
if (! $hostname || ! filter_var($hostname, FILTER_VALIDATE_IP) || ! ctype_digit((string) $vpnPort)
    || ! ctype_digit((string) $agentPort) || ! $name || ! preg_match('/^[\pL\pN ._-]{3,100}$/u', $name)) {
    throw new InvalidArgumentException('Usage: register_remote_openvpn.php <public-ip> <vpn-port> <local-agent-port> <name>');
}
$secret = trim(stream_get_contents(STDIN));
if (strlen($secret) < 32) {
    throw new RuntimeException('The agent enrollment secret is unavailable.');
}
$server = App\Models\VpnServer::firstOrNew(['adapter' => 'linux-openvpn', 'hostname' => $hostname, 'port' => (int) $vpnPort]);
$server->fill(['name' => $name, 'location' => 'Remote VPS', 'capabilities' => ['protocols' => ['openvpn']],
    'is_demo' => false, 'tls_verify' => true,
    'encrypted_credentials' => Illuminate\Support\Facades\Crypt::encryptString(json_encode([
        'agent_shared_secret' => $secret, 'agent_port' => (int) $agentPort,
    ], JSON_THROW_ON_ERROR))]);
$result = (new App\Services\Servers\LinuxOpenVpnAdapter)->testConnection($server);
if (($result['status'] ?? '') !== 'online') {
    throw new RuntimeException('The tunnel or remote OpenVPN agent is not ready.');
}
$server->fill(['status' => 'online', 'vpn_status' => 'online', 'last_checked_at' => now(),
    'last_latency_ms' => $result['latency_ms'], 'software_version' => $result['data']['version'] ?? null]);
$server->save();
App\Models\OperationLog::create(['vpn_server_id' => $server->id, 'operation' => 'register_remote_linux_openvpn',
    'status' => 'success', 'message' => 'Remote OpenVPN agent verified over its local SSH tunnel.', 'completed_at' => now()]);
echo json_encode(['server_id' => $server->id, 'name' => $server->name, 'endpoint' => $server->hostname.':'.$server->port, 'status' => $server->status], JSON_PRETTY_PRINT).PHP_EOL;
