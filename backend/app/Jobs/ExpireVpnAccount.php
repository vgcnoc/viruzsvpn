<?php

namespace App\Jobs;

use App\Models\OperationLog;
use App\Models\VpnAccount;
use App\Services\Servers\DriverFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ExpireVpnAccount implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 6;

    public array $backoff = [60, 300, 900, 1800, 3600];

    public function __construct(public int $accountId) {}

    public function handle(DriverFactory $drivers): void
    {
        $account = VpnAccount::with('server')->find($this->accountId);
        if (! $account || $account->status !== 'expiring' || ! $account->expires_at?->isPast()) {
            return;
        }

        $log = OperationLog::create([
            'vpn_server_id' => $account->vpn_server_id, 'vpn_account_id' => $account->id,
            'operation' => 'expire_account', 'status' => 'running', 'idempotency_key' => $account->idempotency_key,
        ]);

        try {
            $result = $drivers->for($account->server)->deleteAccount($account->server, $account);
            if (! ($result['supported'] ?? false) || ! in_array($result['status'] ?? '', ['success', 'demo'], true)) {
                throw new \RuntimeException($result['message'] ?? 'The server did not revoke the expired account.');
            }
            $account->update(['status' => 'expired']);
            if ($account->remote_rule_status && $account->remote_public_port && $account->server->adapter === 'mikrotik-routeros') {
                $remoteResult = app(\App\Services\Servers\MikroTikAdapter::class)->removeRemoteAccess($account->server, $account);
                $account->update(['remote_rule_status' => $remoteResult['status'] === 'success' ? 'removed' : 'cleanup_failed']);
            }
            $log->update(['status' => 'success', 'message' => $result['message'] ?? 'Expired account revoked on server.', 'details' => $result, 'completed_at' => now()]);
        } catch (\Throwable $exception) {
            $log->update(['status' => 'failed', 'message' => 'Expiry revocation failed; the job will retry.', 'completed_at' => now()]);
            throw $exception;
        }
    }
}
