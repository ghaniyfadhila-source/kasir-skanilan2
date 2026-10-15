<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ticket 12 - Audit Logs + Admin Settings.
 *
 * Pemisahanktor vs subjek:
 * - `user_id`  = SUBJEK. Siapa/pelanggan yang kena aksi (siswa yang top-up saldo,
 *                pembeli yang tap). Ini sudah dipakai Ticket 06 dan 08.
 * - `actor_id` = AKTOR. Siapa yang SEDANG melakukan aksi (admin yang menekan
 *                tombol). Tanpa kolom ini, jejak audit tidak bisa menjawab
 *                pertanyaan "siapa yang melakukannya".
 * - `ip_address` untukinvestigasi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->foreignUuid('actor_id')->nullable()->after('id')->constrained('users')->nullOnDelete();
            $table->string('ip_address', 45)->nullable()->after('console_id');
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->index(['actor_id', 'created_at']);
            $table->index(['action', 'created_at']);
        });

        // Pengaturan aplikasi (key-value).
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key', 100)->primary();
            $table->text('value')->nullable();
            $table->string('type', 20)->default('string'); // string|int|bool|json
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(['actor_id', 'created_at']);
            $table->dropIndex(['action', 'created_at']);
            $table->dropForeign(['actor_id']);
            $table->dropColumn(['actor_id', 'ip_address']);
        });
    }
};