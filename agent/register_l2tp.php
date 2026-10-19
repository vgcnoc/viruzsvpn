<?php

declare(strict_types=1);

use App\Models\OperationLog;
use App\Models\VpnServer;
use App\Services\Servers\LinuxL2tpAdapter;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Crypt;

require '/var/www/viruzsvpn/backend/vendor/autoload.php';
$app = require '/var/www/viruzsvpn/backend/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$environment = parse_ini_file('/etc/virvpn-l2tp/agent.env', false, INI_SCANNER_RAW);
$secret = $environment['VPN_AGENT_SHARED_SECRET'] ?? '';
$psk = $environment['VV_L2TP_IPSEC_PSK'] ?? '';
if (strlen($secret) < 32 || strlen($psk) < 16) {
    throw new RuntimeException('The L2TP agent secret or IPsec PSK is unavailable.');
}
$server = VpnServer::firstOrNew(['adapter' => 'linux-l2tp', 'hostname' => '139.190.96.74', 'port' => 1701]);
$server->fill([
    'name' => 'VPS VIRUZS - L2TP/IPsec', 'location' => 'VPS VIRUZS',
    'capabilities' => ['protocols' => ['l2tp-ipsec']], 'is_demo' => false, 'tls_verify' => true,
    'encrypted_credentials' => Crypt::encryptString(json_encode([
        'agent_shared_secret' => $secret, 'agent_port' => 9447, 'l2tp_ipsec_psk' => $psk,
    ], JSON_THROW_ON_ERROR)),
]);
$result = (new LinuxL2tpAdapter)->testConnection($server);
if (($result['status'] ?? '') !== 'online') {
    throw new RuntimeException('The L2TP/IPsec agent is not ready.');
}
$server->fill(['status' => 'online', 'vpn_status' => 'online', 'last_checked_at' => now(),
    'last_latency_ms' => $result['latency_ms'], 'software_version' => $result['data']['version'] ?? null]);
$server->save();
OperationLog::create(['vpn_server_id' => $server->id, 'operation' => 'register_linux_l2tp_ipsec',
    'status' => 'success', 'message' => 'L2TP/IPsec agent verified and registered without changing legacy PPP accounts.', 'completed_at' => now()]);
echo json_encode(['server_id' => $server->id, 'name' => $server->name, 'endpoint' => $server->hostname.':'.$server->port,
    'status' => $server->status], JSON_PRETTY_PRINT).PHP_EOL;
