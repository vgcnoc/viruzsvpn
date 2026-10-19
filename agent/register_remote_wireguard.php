<?php

declare(strict_types=1);

use App\Models\VpnServer;
use App\Services\Servers\LinuxAgentAdapter;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Crypt;

if ($argc !== 5) {
    fwrite(STDERR, "Usage: php register_remote_wireguard.php <public-ip> <wireguard-port> <local-agent-port> <name>\n");
    exit(64);
}
[, $hostname, $wireguardPort, $agentPort, $name] = $argv;
if (! filter_var($hostname, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || ! ctype_digit($wireguardPort) || ! ctype_digit($agentPort)
    || (int) $wireguardPort < 1 || (int) $wireguardPort > 65535 || (int) $agentPort < 1024 || (int) $agentPort > 65535
    || ! preg_match('/^[A-Za-z0-9 ._-]{3,100}$/', $name)) {
    fwrite(STDERR, "Invalid server registration values.\n");
    exit(64);
}
$secret = trim((string) stream_get_contents(STDIN));
if (strlen($secret) < 32 || strlen($secret) > 512) {
    fwrite(STDERR, "The remote agent secret is invalid.\n");
    exit(64);
}
require __DIR__.'/../backend/vendor/autoload.php';
$app = require __DIR__.'/../backend/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$server = VpnServer::firstOrNew(['adapter' => 'linux-agent', 'hostname' => $hostname, 'port' => (int) $wireguardPort]);
$server->fill([
    'name' => $name, 'location' => 'Remote VPS', 'status' => 'unknown', 'vpn_status' => 'unknown',
    'tls_verify' => false, 'is_demo' => false, 'capabilities' => ['protocols' => ['wireguard']],
    'encrypted_credentials' => Crypt::encryptString(json_encode(['agent_shared_secret' => $secret, 'agent_port' => (int) $agentPort], JSON_THROW_ON_ERROR)),
]);
$server->save();
$result = (new LinuxAgentAdapter)->testConnection($server);
$wireGuard = data_get($result, 'data.vpn_services.wireguard', []);
$capabilities = ['protocols' => ['wireguard']];
if (preg_match('/^(?:[0-9]{1,3}\.){3}[0-9]{1,3}\/(?:[0-9]|[12][0-9]|3[0-2])$/', (string) ($wireGuard['peer_network'] ?? ''))) {
    $capabilities['wireguard_peer_cidr'] = $wireGuard['peer_network'];
}
if (preg_match('/^[A-Za-z0-9+\/]{43}=$/', (string) ($wireGuard['server_public_key'] ?? ''))) {
    $capabilities['wireguard_public_key'] = $wireGuard['server_public_key'];
}
$server->update([
    'status' => $result['status'] ?? 'unknown', 'vpn_status' => data_get($result, 'data.vpn_services.wireguard.status') ?? 'unknown',
    'last_checked_at' => now(), 'last_latency_ms' => $result['latency_ms'] ?? null,
    'capabilities' => $capabilities,
]);
fwrite(STDOUT, "Registered server #{$server->id}: {$server->name} ({$server->status}).\n");
