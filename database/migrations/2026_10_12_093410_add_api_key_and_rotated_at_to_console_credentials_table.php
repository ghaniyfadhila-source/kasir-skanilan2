<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('console_credentials', function (Blueprint $table) {
            $table->char('api_key', 64)->unique()->after('password_hash');
            $table->timestamp('rotated_at')->nullable()->after('api_key');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('console_credentials', function (Blueprint $table) {
            $table->dropColumn(['api_key', 'rotated_at']);
        });
    }
};