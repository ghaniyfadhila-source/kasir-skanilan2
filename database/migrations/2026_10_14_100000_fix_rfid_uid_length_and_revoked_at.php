<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rapikan kolom rfid_cards sesuai SPEC:
 * - rfid_uid: CHAR(14) -> CHAR(8)  (MIFARE Classic 4-byte = 8 hex char)
 *   CHAR(14) di PostgreSQL/MariaDB mem-pad nilai dengan spasi sehingga
 *   perbandingan UID dan unique index tidak konsisten.
 * - revoked_at: nullable timestamp
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rfid_cards', function (Blueprint $table) {
            $table->char('rfid_uid', 8)->change();
        });

        if (! Schema::hasColumn('rfid_cards', 'revoked_at')) {
            Schema::table('rfid_cards', function (Blueprint $table) {
                $table->timestamp('revoked_at')->nullable()->after('is_active');
            });
        }

        // Bersihkan sisa padding spasi dari data lama.
        DB::table('rfid_cards')
            ->whereRaw("LENGTH(rfid_uid) > 8")
            ->update(['rfid_uid' => DB::raw("UPPER(TRIM(rfid_uid))")]);
    }

    public function down(): void
    {
        Schema::table('rfid_cards', function (Blueprint $table) {
            $table->char('rfid_uid', 14)->change();
        });
    }
};