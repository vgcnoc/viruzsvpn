<?php

use App\Jobs\ExpireVpnAccount;
use App\Models\VpnAccount;
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
