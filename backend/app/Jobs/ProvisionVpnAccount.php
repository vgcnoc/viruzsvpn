<?php

namespace App\Jobs;

use App\Models\OperationLog;
use App\Models\VpnAccount;
use App\Models\VpnOrder;
use App\Services\Servers\DriverFactory;
use App\Services\Servers\AutomaticRemoteAccess;
use App\Services\Servers\MikroTikAdapter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class ProvisionVpnAccount implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $backoff = 15;

    public int $timeout = 60;

    public function __construct(public int $accountId, public ?int $actorId = null) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('vpn-provision:'.$this->accountId))->releaseAfter(15)->expireAfter(75)];
    }

    public function handle(DriverFactory $drivers, ?AutomaticRemoteAccess $remote = null): void
    {
        $remote ??= app(AutomaticRemoteAccess::class);
        $account = VpnAccount::with('server')->findOrFail($this->accountId);
        if (! in_array($account->status, ['pending', 'provisioning'], true)) {
            return;
        }

        $account->update(['status' => 'provisioning', 'provisioning_error' => null]);
        $log = OperationLog::create([
            'user_id' => $this->actorId, 'vpn_server_id' => $account->vpn_server_id,
            'vpn_account_id' => $account->id, 'operation' => 'create_account',
            'status' => 'running', 'idempotency_key' => $account->idempotency_key,
        ]);

        try {
            $blockedPorts = [];
            $blockedIps = [];
            if ($remote->enabledFor($account)) {
                $routerOs = app(MikroTikAdapter::class);
                $prerequisites = $routerOs->remotePrerequisites($account->server);
                if (($prerequisites['status'] ?? '') !== 'success') {
                    throw new \RuntimeException($prerequisites['message'] ?? 'CHR remote prerequisites are not ready.');
                }
                $inventory = $routerOs->usedPublicTcpPorts($account->server);
                if (($inventory['status'] ?? '') !== 'success') {
                    throw new \RuntimeException($inventory['message'] ?? 'Could not check existing CHR ports.');
                }
                $blockedPorts = array_fill_keys($inventory['ports'], true);
                $addresses = $routerOs->usedTunnelIps($account->server, $account);
                if (($addresses['status'] ?? '') !== 'success') {
                    throw new \RuntimeException($addresses['message'] ?? 'Could not check existing CHR addresses.');
                }
                $blockedIps = $addresses['addresses'];
            }
            $remote->prepare($account, $blockedPorts, $blockedIps);
            $result = $drivers->for($account->server)->createAccount($account->server, $account);
            if (($result['supported'] ?? false) && in_array($result['status'] ?? '', ['success', 'demo'], true)) {
                if ($account->server->adapter === 'linux-openvpn') {
                    $allocation = array_intersect_key($result['data'] ?? [], array_flip([
                        'allocated_ip', 'remote_public_port', 'remote_target_port', 'remote_allowed_cidr',
                        'remote_gateway_ip', 'remote_wan_interface', 'remote_rule_status',
                    ]));
                    $account->update($allocation);
                }
                if ($remote->enabledFor($account)) {
                    $remoteResult = app(MikroTikAdapter::class)->syncRemoteAccess($account->server, $account);
                    if (($remoteResult['status'] ?? '') !== 'success') {
                        $account->update(['remote_rule_status' => 'failed']);
                        $rollback = $drivers->for($account->server)->deleteAccount($account->server, $account);
                        $message = $remoteResult['message'] ?? 'Remote firewall provisioning failed.';
                        if (($rollback['status'] ?? '') !== 'success') {
                            $message .= ' PPP rollback also failed; verify the managed account on CHR.';
                        }
                        throw new \RuntimeException($message);
                    }
                    $account->update(['remote_rule_status' => 'active']);
                }
                $order = VpnOrder::where('vpn_account_id', $account->id)->where('type', 'new')->where('status', 'paid')->first();
                $manualPlan = ! $order && $account->plan_id && $account->subscriptions()->where('status', 'pending_activation')->exists()
                    ? $account->plan : null;
                $startedAt = ($order || $manualPlan) ? now() : $account->starts_at;
                $expiresAt = $order ? $startedAt->copy()->addDays($order->duration_days)
                    : ($manualPlan ? $startedAt->copy()->addDays($manualPlan->duration_days) : $account->expires_at);
                $account->update([
                    'status' => 'active', 'remote_reference' => $result['remote_reference'] ?? data_get($result, 'data.remote_reference'),
                    'encrypted_provisioning_secret' => null, 'starts_at' => $startedAt, 'expires_at' => $expiresAt,
                ]);
                if ($order) {
                    $account->subscriptions()->where('notes', $order->invoice_number)->where('status', 'pending_activation')
                        ->update(['status' => 'active', 'starts_at' => $startedAt, 'expires_at' => $expiresAt]);
                } elseif ($manualPlan) {
                    $account->subscriptions()->where('status', 'pending_activation')
                        ->update(['status' => 'active', 'starts_at' => $startedAt, 'expires_at' => $expiresAt]);
                }
                $log->update(['status' => 'success', 'message' => $result['message'] ?? null, 'details' => $result, 'completed_at' => now()]);

                return;
            }

            $message = $result['message'] ?? 'Server adapter could not provision this account.';
            $account->update(['status' => 'failed', 'provisioning_error' => $message]);
            $log->update(['status' => 'failed', 'message' => $message, 'details' => $result, 'completed_at' => now()]);
        } catch (\Throwable $exception) {
            report($exception);
            $account->update(['status' => 'failed', 'provisioning_error' => 'Provisioning failed. See the operation log.']);
            $log->update(['status' => 'failed', 'message' => $exception->getMessage(), 'completed_at' => now()]);
        }
    }
}
