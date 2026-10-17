<?php

use App\Jobs\ExpireVpnAccount;
use App\Models\VpnAccount;
use App\Models\VpnOrder;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(function (): void {
    VpnAccount::query()
        ->whereIn('status', ['active', 'suspended'])
        ->whereNotNull('expires_at')
        ->where('expires_at', '<=', now())
        ->eachById(function (VpnAccount $account): void {
            $account->update(['status' => 'expiring']);
            ExpireVpnAccount::dispatch($account->id);
        });
})->name('expire-vpn-accounts')->everyMinute()->withoutOverlapping();

Schedule::command('sanctum:prune-expired --hours=24')->daily();

Schedule::call(function (): void {
    VpnOrder::query()->where('status', 'pending_payment')->where('payment_due_at', '<=', now())
        ->eachById(fn (VpnOrder $order) => $order->update(['status' => 'expired', 'encrypted_client_password' => null]));
})->name('expire-unpaid-vpn-orders')->hourly()->withoutOverlapping();
