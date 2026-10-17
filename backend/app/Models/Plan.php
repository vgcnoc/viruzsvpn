<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    protected $fillable = [
        'name', 'description', 'price_cents', 'currency', 'duration_days', 'allowed_protocols',
        'allowed_server_ids', 'connection_limit', 'data_quota_bytes', 'bandwidth_mbps', 'is_active',
    ];

    protected function casts(): array
    {
        return ['allowed_protocols' => 'array', 'allowed_server_ids' => 'array', 'is_active' => 'boolean'];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(VpnOrder::class);
    }
}
