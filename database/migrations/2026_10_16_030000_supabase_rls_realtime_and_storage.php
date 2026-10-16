<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mengaktifkan Row Level Security, mendaftarkan tabel ke Realtime, dan membuat
 * bucket firmware.
 *
 *Migration ini hanya berlaku di PostgreSQL. Di SQLite semua bagian dilewati karena
 * RLS, publication, dan storage.buckets tidak ada di sana, sedangkan
 * pengembangan lokal memakai SQLite.
 */
return new class extends Migration
{
    /**
     * Tabel milik aplikasi. Semua harus dilindungi RLS.
     *
     * @var string[]
     */
    private const APP_TABLES = [
        'users',
        'rfid_cards',
        'consoles',
        'console_credentials',
        'categories',
        'menu_items',
        'transactions',
        'transaction_items',
        'audit_logs',
        'password_resets',
        'settings',
        'sessions',
        'cache',
        'cache_locks',
        'personal_access_tokens',
    ];

    /**
     * Tabel yang didengarkan lewat Realtime.
     *
     * @var string[]
     */
    private const REALTIME_TABLES = [
        'consoles',
        'menu_items',
        'transactions',
        'transaction_items',
        'rfid_cards',
        'users',
    ];

    /**
     * Bucket Storage untuk firmware ESP8266 (Ticket 14).
     */
    private const FIRMWARE_BUCKET = 'firmware';

    public function up(): void
    {
        if (! $this->onPostgres()) {
            return;
        }

        $this->enableRowLevelSecurity();
        $this->grantReadOnlyPolicies();
        $this->registerRealtimeTables();
        $this->createFirmwareBucket();
    }

    public function down(): void
    {
        if (! $this->onPostgres()) {
            return;
        }

        // Menonaktifkan RLS mengembalikan kebocoran lewat kunci anon, jadi
        // `down()` sengaja tidak melakukannya. Membatalkan migration ini hanya
        // perlu untuk development, bukan untuk rollback produksi.
        DB::unprepared('DROP POLICY IF EXISTS anon_read_active_menus ON menu_items');

        foreach (self::REALTIME_TABLES as $table) {
            try {
                DB::unprepared("ALTER PUBLICATION supabase_realtime DROP TABLE IF EXISTS public.{$table}");
            } catch (Throwable) {
                // Tabel belum terdaftar; abaikan.
            }
        }
    }

    /**
     * Aktifkan RLS di seluruh tabel aplikasi.
     *
     * Tanpa ini, kunci `anon` yang ikut terpasang di browser Cashier Web UI bisa
     * membaca `users` (berisi password hash) dan `console_credentials` (berisi
     * `api_key`) lewat PostgREST. Role `anon` memang sudah diberi hak SELECT
     * oleh Supabase; RLS adalah satu-satunya penyaringnya.
     *
     * Role `postgres` yang dipakai Laravel punya `rolbypassrls`, jadi
     * mengaktifkan RLS tidak mengganggu aplikasi.
     */
    private function enableRowLevelSecurity(): void
    {
        foreach (self::APP_TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        }
    }

    /**
     * Satu-satunya policy yang diberikan ke `anon`.
     *
     * Menu yang aktif memang data publik: ditampilkan di layar kasir dan dibaca
     * ESP8266 saat boot. Tabel lain sengaja dibiarkan tanpa policy, jadi akses
     * `anon` hasilnya selalu kosong.
     *
     * Akses tulis dan baca data sensitif tetap lewat REST API Laravel, bukan
     * lewat PostgREST.
     */
    private function grantReadOnlyPolicies(): void
    {
        DB::unprepared('DROP POLICY IF EXISTS anon_read_active_menus ON menu_items');

        DB::unprepared(
            'CREATE POLICY anon_read_active_menus ON menu_items
                FOR SELECT TO anon
                USING (is_active = true)'
        );
    }

    private function registerRealtimeTables(): void
    {
        // Publication bawaan Supabase biasanya sudah ada, tapi project yang
        // sangat baru belum tentu.
        DB::unprepared(
            "DO $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_publication WHERE pubname = 'supabase_realtime') THEN
                    CREATE PUBLICATION supabase_realtime;
                END IF;
            END
            $$"
        );

        foreach (self::REALTIME_TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            try {
                DB::unprepared("ALTER PUBLICATION supabase_realtime ADD TABLE public.{$table}");
            } catch (Throwable $e) {
                // Tabel sudah terdaftar dari migration sebelumnya.
                if (! str_contains($e->getMessage(), 'duplicate_object')) {
                    throw $e;
                }
            }
        }
    }

    private function createFirmwareBucket(): void
    {
        // Bucket WAJIB private: firmware hanya boleh diambil lewat signed URL
        // berumur pendek dari backend, bukan URL publik.
        DB::unprepared(
            "INSERT INTO storage.buckets (id, name, public)
             VALUES ('".self::FIRMWARE_BUCKET."', '".self::FIRMWARE_BUCKET."', false)
             ON CONFLICT (id) DO NOTHING"
        );
    }

    private function onPostgres(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }
};