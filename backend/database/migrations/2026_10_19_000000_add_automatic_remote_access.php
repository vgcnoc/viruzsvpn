<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vpn_orders', function (Blueprint $table) {
            $table->string('remote_allowed_cidr', 18)->nullable();
        });
        Schema::table('vpn_accounts', function (Blueprint $table) {
            $table->string('remote_rule_status', 24)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('vpn_accounts', fn (Blueprint $table) => $table->dropColumn('remote_rule_status'));
        Schema::table('vpn_orders', fn (Blueprint $table) => $table->dropColumn('remote_allowed_cidr'));
    }
};
