<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('console_credentials', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('console_id')->constrained()->cascadeOnDelete();
            $table->string('password_hash', 255);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('console_credentials');
    }
};