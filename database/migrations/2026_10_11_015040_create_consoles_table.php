<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consoles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('device_id', 50)->unique(); // KSR-001
            $table->string('name', 100);
            $table->string('location', 255)->nullable();
            $table->string('status', 20)->default('offline'); // online | offline | error
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->string('firmware_version', 20)->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->integer('wifi_rssi')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consoles');
    }
};