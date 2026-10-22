<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ticket 10: laporan isi antrean offline dari perangkat.
 *
 * Perangkat mengirim jumlah tap yang masih menunggu dan yang sudah menyerah
 * (retry > 3) pada setiap heartbeat. Angka ini yang membuat "manual
 * intervention" mungkin dilakukan: admin bisa melihat ada antrean macet di
 * halaman console lalu mengirim perintah `queue.clear`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consoles', function (Blueprint $table) {
            $table->unsignedInteger('queue_pending')->default(0)->after('wifi_rssi');
            $table->unsignedInteger('queue_failed')->default(0)->after('queue_pending');
            $table->timestamp('queue_reported_at')->nullable()->after('queue_failed');
        });
    }

    public function down(): void
    {
        Schema::table('consoles', function (Blueprint $table) {
            $table->dropColumn(['queue_pending', 'queue_failed', 'queue_reported_at']);
        });
    }
};
