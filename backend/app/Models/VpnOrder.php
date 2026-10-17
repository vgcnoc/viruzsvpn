<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VpnOrder extends Model
{
    protected $fillable = [
        'invoice_number', 'user_id', 'plan_id', 'vpn_server_id', 'vpn_account_id', 'type',
        'protocol', 'identifier', 'encrypted_client_password', 'status', 'amount_cents',
        'currency', 'plan_name', 'duration_days', 'payment_reference', 'payment_note',
        'admin_note', 'payment_due_at', 'submitted_at', 'paid_at',
        'payment_bank_name', 'payment_account_name', 'payment_account_number', 'payment_instructions',
    ];

    protected $hidden = ['encrypted_client_password'];

    protected function casts(): array
    {
        return ['payment_due_at' => 'datetime', 'submitted_at' => 'datetime', 'paid_at' => 'datetime'];
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function plan(): BelongsTo { return $this->belongsTo(Plan::class); }
    public function server(): BelongsTo { return $this->belongsTo(VpnServer::class, 'vpn_server_id'); }
    public function account(): BelongsTo { return $this->belongsTo(VpnAccount::class, 'vpn_account_id'); }
}
