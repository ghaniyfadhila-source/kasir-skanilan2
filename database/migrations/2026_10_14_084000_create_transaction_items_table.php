<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaction_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('menu_item_id')->constrained()->cascadeOnDelete();
            $table->integer('quantity');
            $table->bigInteger('unit_price_cents');
            $table->timestamps();
        });

        Schema::table('transaction_items', function (Blueprint $table) {
            $table->index(['transaction_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_items');
    }
};