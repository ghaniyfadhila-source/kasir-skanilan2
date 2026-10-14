<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('device_id', 17)->nullable()->after('balance_cents');
            $table->foreign('device_id')->references('device_id')->on('consoles')->nullOnDelete();
            $table->index('device_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['device_id']);
            $table->dropIndex(['device_id']);
            $table->dropColumn('device_id');
        });
    }
};