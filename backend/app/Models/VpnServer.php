<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VpnServer extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'adapter', 'hostname', 'port', 'location', 'description', 'tags',
        'encrypted_credentials', 'tls_verify', 'status', 'vpn_status', 'last_checked_at',
        'last_latency_ms', 'software_version', 'capabilities', 'is_demo',
    ];

    protected $hidden = ['encrypted_credentials'];

    protected function casts(): array
    {
        return [
            'tags' => 'array', 'capabilities' => 'array', 'tls_verify' => 'boolean',
            'is_demo' => 'boolean', 'last_checked_at' => 'datetime',
        ];
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(VpnAccount::class);
    }
}
