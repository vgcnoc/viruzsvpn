<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('administrator')->after('email');
        });

        Schema::create('vpn_servers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('adapter');
            $table->string('hostname');
            $table->unsignedInteger('port');
            $table->string('location')->nullable();
            $table->text('description')->nullable();
            $table->json('tags')->nullable();
            $table->text('encrypted_credentials')->nullable();
            $table->boolean('tls_verify')->default(true);
            $table->string('status')->default('unknown');
            $table->string('vpn_status')->default('unknown');
            $table->timestamp('last_checked_at')->nullable();
            $table->unsignedInteger('last_latency_ms')->nullable();
            $table->string('software_version')->nullable();
            $table->json('capabilities')->nullable();
            $table->boolean('is_demo')->default(false);
            $table->timestamps();
            $table->index(['adapter', 'status']);
        });

        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->unsignedInteger('price_cents');
            $table->string('currency', 3)->default('IDR');
            $table->unsignedInteger('duration_days');
            $table->json('allowed_protocols');
            $table->json('allowed_server_ids')->nullable();
            $table->unsignedSmallInteger('connection_limit')->default(1);
            $table->unsignedBigInteger('data_quota_bytes')->nullable();
            $table->unsignedInteger('bandwidth_mbps')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('vpn_accounts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('vpn_server_id')->constrained('vpn_servers')->restrictOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();
            $table->string('protocol');
            $table->string('identifier');
            $table->string('allocated_ip')->nullable();
            $table->string('status')->default('pending');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedSmallInteger('device_limit')->nullable();
            $table->unsignedInteger('bandwidth_mbps')->nullable();
            $table->unsignedBigInteger('data_quota_bytes')->nullable();
            $table->string('remote_reference')->nullable();
            $table->text('provisioning_error')->nullable();
            $table->uuid('idempotency_key')->unique();
            $table->timestamps();
            $table->unique(['vpn_server_id', 'protocol', 'identifier']);
            $table->index(['status', 'expires_at']);
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vpn_account_id')->constrained('vpn_accounts')->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->timestamp('starts_at');
            $table->timestamp('expires_at');
            $table->string('status')->default('active');
            $table->unsignedInteger('amount_cents');
            $table->string('currency', 3);
            $table->string('change_type')->default('initial');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['status', 'expires_at']);
        });

        Schema::create('operation_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('vpn_server_id')->nullable()->constrained('vpn_servers')->nullOnDelete();
            $table->foreignId('vpn_account_id')->nullable()->constrained('vpn_accounts')->nullOnDelete();
            $table->string('operation');
            $table->string('status')->default('pending');
            $table->string('idempotency_key')->nullable()->index();
            $table->text('message')->nullable();
            $table->json('details')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['vpn_server_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operation_logs');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('vpn_accounts');
        Schema::dropIfExists('plans');
        Schema::dropIfExists('vpn_servers');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('role'));
    }
};
