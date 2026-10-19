<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OperationLog;
use App\Models\BillingSetting;
use App\Models\User;
use App\Models\VpnAccount;
use App\Models\VpnOrder;
use App\Models\VpnServer;
use App\Services\EmailDelivery;

class DashboardController extends Controller
{
    public function __invoke(EmailDelivery $emailDelivery)
    {
        $accounts = VpnAccount::query();
        $billing = BillingSetting::first();

        return response()->json([
            'summary' => [
                'servers' => VpnServer::count(), 'servers_online' => VpnServer::where('status', 'online')->count(),
                'accounts_active' => (clone $accounts)->where('status', 'active')->count(),
                'accounts_expiring_soon' => (clone $accounts)->where('status', 'active')->whereBetween('expires_at', [now(), now()->addDays(7)])->count(),
                'customers' => User::where('role', 'customer')->count(),
                'payments_to_verify' => VpnOrder::where('status', 'payment_submitted')->count(),
                'orders_pending_payment' => VpnOrder::where('status', 'pending_payment')->count(),
            ],
            'billing_readiness' => [
                'payment_method_ready' => (bool) ($billing?->bank_name && $billing?->account_name && $billing?->account_number),
                'email_delivery_ready' => $emailDelivery->isReady(),
            ],
            'recent_operations' => OperationLog::with(['server:id,name', 'account:id,identifier'])->latest()->limit(8)->get(),
            'demo_data' => VpnServer::where('is_demo', true)->exists(),
        ]);
    }
}
