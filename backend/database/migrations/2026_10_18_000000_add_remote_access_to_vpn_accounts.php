<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vpn_accounts', function (Blueprint $table) {
            $table->unsignedSmallInteger('remote_public_port')->nullable();
            $table->unsignedSmallInteger('remote_target_port')->nullable();
            $table->string('remote_service', 24)->nullable();
            $table->string('client_device_type', 24)->nullable();
            $table->string('remote_device_ip', 45)->nullable();
            $table->string('remote_allowed_cidr', 18)->nullable();
            $table->string('remote_wan_interface', 64)->nullable();
            $table->string('remote_gateway_ip', 45)->nullable();
            $table->unique(['vpn_server_id', 'remote_public_port'], 'vpn_accounts_server_remote_port_unique');
        });
        Schema::table('vpn_orders', function (Blueprint $table) {
            $table->unsignedSmallInteger('remote_target_port')->nullable();
            $table->string('remote_service', 24)->nullable();
            $table->string('client_device_type', 24)->nullable();
            $table->string('remote_device_ip', 45)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('vpn_orders', function (Blueprint $table) {
            $table->dropColumn(['remote_target_port', 'remote_service', 'client_device_type', 'remote_device_ip']);
        });
        Schema::table('vpn_accounts', function (Blueprint $table) {
            $table->dropUnique('vpn_accounts_server_remote_port_unique');
            $table->dropColumn(['remote_public_port', 'remote_target_port', 'remote_service', 'client_device_type', 'remote_device_ip', 'remote_allowed_cidr', 'remote_wan_interface', 'remote_gateway_ip']);
        });
    }
};
