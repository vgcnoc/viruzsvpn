<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VpnAccount extends Model
{
    protected $hidden = ['encrypted_provisioning_secret'];

    protected $fillable = [
        'uuid', 'vpn_server_id', 'plan_id', 'protocol', 'identifier', 'allocated_ip', 'status',
        'starts_at', 'expires_at', 'device_limit', 'bandwidth_mbps', 'data_quota_bytes',
        'remote_reference', 'adapter_payload', 'encrypted_provisioning_secret', 'provisioning_error', 'idempotency_key',
    ];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'expires_at' => 'datetime', 'adapter_payload' => 'array'];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(VpnServer::class, 'vpn_server_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function operationLogs(): HasMany
    {
        return $this->hasMany(OperationLog::class);
    }
}
