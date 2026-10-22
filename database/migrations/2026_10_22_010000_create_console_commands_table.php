<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ticket 10: perintah dari admin ke perangkat kasir.
 *
 * Firmware ESP8266 sengaja tidak berlangganan WebSocket (heap ~40 KB tidak
 * cukup untuk klien Phoenix), jadi perintah seperti `queue.clear` tidak bisa
 * dikirim lewat Realtime. Sebagai gantinya perintah disimpan di tabel ini dan
 * diambil perangkat pada heartbeat / sync berikutnya. Perintah diulang sampai
 * perangkat mengirim ack, sehingga kehilangan satu respons HTTP tidak
 * menghilangkan perintah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('console_commands', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('console_id')->constrained('consoles')->cascadeOnDelete();
            $table->string('command', 64);
            $table->json('payload')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->string('result', 32)->nullable(); // ok | failed
            $table->string('result_message', 255)->nullable();
            $table->timestamps();

            $table->index(['console_id', 'acknowledged_at']);
        });

        // Supabase memberi hak SELECT ke role `anon` untuk setiap tabel baru di
        // schema public. Tanpa RLS, isi perintah (dan payload-nya) bisa dibaca
        // siapa pun yang memegang kunci anon yang tertanam di browser kasir.
        // Tidak ada policy yang dibuat: hanya role backend yang boleh membaca.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE console_commands ENABLE ROW LEVEL SECURITY');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('console_commands');
    }
};
