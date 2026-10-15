<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ticket 08 - Transaction API.
 *
 * - receipt_no : nomor struk yang ditampilkan ke pembeli, dibuat saat transaksi selesai.
 *               Diisi nullable karena order masih `awaiting_rfid` saat order dibuat.
 * - expires_at : batas waktu order menunggu tap RFID. Disimpan sebagai kolom (bukan
 *               dihitung dari created_at) supaya scheduler bisa men-query order basi.
 * - tap_idempotency_key : kunci idempotensi untuk event tap RFID. Terpisah dari
 *               `idempotency_key` milik pembuatan order, karena satu order punya
 *               dua event terpisah yang masing-masing harus bisa diulang dengan aman.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('receipt_no', 20)->nullable()->unique()->after('idempotency_key');
            $table->string('tap_idempotency_key', 64)->nullable()->unique()->after('idempotency_key');
            $table->timestamp('expires_at')->nullable()->after('status');
        });

        // Index untuk scheduler auto-void: hanya order yang masih menunggu tap.
        Schema::table('transactions', function (Blueprint $table) {
            $table->index(['status', 'expires_at'], 'idx_transactions_pending_expiry');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex('idx_transactions_pending_expiry');
            $table->dropUnique(['receipt_no']);
            $table->dropUnique(['tap_idempotency_key']);
            $table->dropColumn(['receipt_no', 'tap_idempotency_key', 'expires_at']);
        });
    }
};