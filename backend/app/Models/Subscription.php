<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    protected $fillable = [
        'vpn_account_id', 'plan_id', 'starts_at', 'expires_at', 'status', 'amount_cents',
        'currency', 'change_type', 'notes',
    ];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(VpnAccount::class, 'vpn_account_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
