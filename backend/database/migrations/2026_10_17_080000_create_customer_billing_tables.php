<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vpn_accounts', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->nullOnDelete();
        });

        Schema::create('billing_settings', function (Blueprint $table) {
            $table->id();
            $table->string('bank_name')->nullable();
            $table->string('account_name')->nullable();
            $table->string('account_number')->nullable();
            $table->text('instructions')->nullable();
            $table->timestamps();
        });

        Schema::create('vpn_orders', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_number')->nullable()->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->foreignId('vpn_server_id')->nullable()->constrained('vpn_servers')->restrictOnDelete();
            $table->foreignId('vpn_account_id')->nullable()->constrained('vpn_accounts')->restrictOnDelete();
            $table->string('type')->default('new');
            $table->string('protocol')->nullable();
            $table->string('identifier')->nullable();
            $table->text('encrypted_client_password')->nullable();
            $table->string('status')->default('pending_payment');
            $table->unsignedInteger('amount_cents');
            $table->string('currency', 3);
            $table->string('plan_name');
            $table->unsignedInteger('duration_days');
            $table->string('payment_reference')->nullable();
            $table->text('payment_note')->nullable();
            $table->text('admin_note')->nullable();
            $table->string('payment_bank_name');
            $table->string('payment_account_name');
            $table->string('payment_account_number');
            $table->text('payment_instructions')->nullable();
            $table->timestamp('payment_due_at');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vpn_orders');
        Schema::dropIfExists('billing_settings');
        Schema::table('vpn_accounts', fn (Blueprint $table) => $table->dropConstrainedForeignId('user_id'));
    }
};
