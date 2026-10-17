<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OperationLog;
use App\Models\VpnAccount;
use App\Models\VpnServer;

class DashboardController extends Controller
{
    public function __invoke()
    {
        $accounts = VpnAccount::query();

        return response()->json([
            'summary' => [
                'servers' => VpnServer::count(), 'servers_online' => VpnServer::where('status', 'online')->count(),
                'accounts_active' => (clone $accounts)->where('status', 'active')->count(),
                'accounts_expiring_soon' => (clone $accounts)->where('status', 'active')->whereBetween('expires_at', [now(), now()->addDays(7)])->count(),
            ],
            'recent_operations' => OperationLog::with(['server:id,name', 'account:id,identifier'])->latest()->limit(8)->get(),
            'demo_data' => VpnServer::where('is_demo', true)->exists(),
        ]);
    }
}
