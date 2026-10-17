<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperationLog extends Model
{
    protected $fillable = [
        'user_id', 'vpn_server_id', 'vpn_account_id', 'operation', 'status', 'idempotency_key',
        'message', 'details', 'completed_at',
    ];

    protected function casts(): array
    {
        return ['details' => 'array', 'completed_at' => 'datetime'];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(VpnServer::class, 'vpn_server_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(VpnAccount::class, 'vpn_account_id');
    }
}
