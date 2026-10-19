<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailSetting extends Model
{
    protected $fillable = ['host', 'port', 'encryption', 'username', 'encrypted_password', 'from_address', 'from_name', 'is_enabled'];

    protected $hidden = ['encrypted_password'];

    protected function casts(): array
    {
        return ['port' => 'integer', 'is_enabled' => 'boolean'];
    }
}
