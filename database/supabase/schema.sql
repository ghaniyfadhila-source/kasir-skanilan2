-- =============================================================================
-- Kasir Skanilan 2 - VERIFIKASI Supabase
-- =============================================================================
--
-- File ini tidak lagi berisi DDL.
--
-- Seluruh skema dibangun dari `php artisan migrate`, termasuk:
--
--   * 15 tabel aplikasi (dengan foreign key dan index)
--   * Row Level Security beserta policy-nya
--   * pendaftaran tabel ke publication `supabase_realtime`
--   * storage bucket `firmware` (private)
--
-- Semuanya ada di migration:
--
--   database/migrations/2026_10_16_030000_supabase_rls_realtime_and_storage.php
--
--
-- DULUNYA file ini berisi CREATE TABLE manual, dan file itulah penyebab
-- schema drift: isanya tertinggal dari migration (kolom `receipt_no`,
-- `api_key`, `actor_id` hilang; `transaction_items`, `settings`, `sessions`,
-- `cache`, `cache_locks`, dan `personal_access_tokens` tidak ada sama sekali).
-- Menambal manual seperti itu akan menghasilkan drift lagi di ticket berikutnya,
-- jadi DDL manual dihapus.
--
--
-- CARA MEMBANGUN SKEMA
-- =============================================================================
--
--   1. Reset schema (hanya untuk project baru):
--
--        DROP SCHEMA public CASCADE;
--        CREATE SCHEMA public;
--        GRANT ALL ON SCHEMA public TO postgres;
--        GRANT ALL ON SCHEMA public TO anon;
--        GRANT ALL ON SCHEMA public TO authenticated;
--
--   2. Jalankan migration:
--
--        php artisan migrate --database=supabase --force
--
--   3. Periksa:
--
--        php artisan supabase:check
--
--
-- QUERY VERIFIKASI
-- =============================================================================
-- Bisa dijalankan langsung di SQL Editor tanpa perlu aplikasi.

-- 1. Tabel aplikasi, harus 15
SELECT count(*) AS tabel_aplikasi
FROM information_schema.tables
WHERE table_schema = 'public'
  AND table_name IN (
    'users', 'rfid_cards', 'consoles', 'console_credentials', 'categories',
    'menu_items', 'transactions', 'transaction_items', 'audit_logs',
    'password_resets', 'settings', 'sessions', 'cache', 'cache_locks',
    'personal_access_tokens'
  );

-- 2. Row Level Security, hasil terakhir harus 'RLS NONAKTIF' = 'migrations' saja.
--    Kalau ada tabel aplikasi lain di sini, kunci `anon` bisa membacanya langsung.
SELECT c.relname AS tabel,
       CASE WHEN c.relrowsecurity THEN 'RLS aktif' ELSE 'RLS NONAKTIF' END AS status
FROM pg_class c
JOIN pg_namespace n ON n.oid = c.relnamespace
WHERE n.nspname = 'public' AND c.relkind = 'r'
ORDER BY c.relname;

-- 3. Tabel yang terdaftar di Realtime, harus 6
SELECT tablename
FROM pg_publication_tables
WHERE pubname = 'supabase_realtime'
ORDER BY tablename;

-- 4. Bucket firmware, `public` harus false
SELECT id, name, public
FROM storage.buckets
WHERE id = 'firmware';

-- 5. Policy untuk role anon, saat ini hanya menu_items yang boleh dibaca
SELECT tablename, policyname, cmd, roles
FROM pg_policies
WHERE schemaname = 'public'
ORDER BY tablename, policyname;