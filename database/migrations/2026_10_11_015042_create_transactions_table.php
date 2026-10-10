<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('console_id')->constrained();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete(); // pembeli
            $table->foreignUuid('cashier_id')->nullable()->constrained('users')->nullOnDelete(); // kasir
            $table->json('items'); // [{"menu_item_id":..., "qty":2, "price_cents":15000, "name":"Nasi Goreng"}]
            $table->bigInteger('total_cents');
            $table->string('status', 20)->default('pending'); // pending | completed | voided | refunded
            $table->string('idempotency_key', 64)->unique();
            $table->string('error_code', 50)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->foreignUuid('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->index(['console_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['status'])->where('status', 'pending');
            $table->index('idempotency_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};