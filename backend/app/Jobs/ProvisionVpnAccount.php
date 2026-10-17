<?php

namespace App\Jobs;

use App\Models\OperationLog;
use App\Models\VpnAccount;
use App\Models\VpnOrder;
use App\Services\Servers\DriverFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProvisionVpnAccount implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $backoff = 15;

    public function __construct(public int $accountId, public ?int $actorId = null) {}

    public function handle(DriverFactory $drivers): void
    {
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
            $result = $drivers->for($account->server)->createAccount($account->server, $account);
            if (($result['supported'] ?? false) && in_array($result['status'] ?? '', ['success', 'demo'], true)) {
                $order = VpnOrder::where('vpn_account_id', $account->id)->where('type', 'new')->where('status', 'paid')->first();
                $startedAt = $order ? now() : $account->starts_at;
                $expiresAt = $order ? $startedAt->copy()->addDays($order->duration_days) : $account->expires_at;
                $account->update([
                    'status' => 'active', 'remote_reference' => $result['remote_reference'] ?? data_get($result, 'data.remote_reference'),
                    'encrypted_provisioning_secret' => null, 'starts_at' => $startedAt, 'expires_at' => $expiresAt,
                ]);
                if ($order) {
                    $account->subscriptions()->where('notes', $order->invoice_number)->where('status', 'pending_activation')
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
