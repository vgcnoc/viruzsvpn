<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vpn_accounts', function (Blueprint $table) {
            $table->json('adapter_payload')->nullable()->after('remote_reference');
            $table->text('encrypted_provisioning_secret')->nullable()->after('adapter_payload');
        });
    }

    public function down(): void
    {
        Schema::table('vpn_accounts', function (Blueprint $table) {
            $table->dropColumn(['adapter_payload', 'encrypted_provisioning_secret']);
        });
    }
};
