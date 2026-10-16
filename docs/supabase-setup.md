# Setup Supabase — Kasir Skanilan 2

Dokumen ini mencatat langkah setup Supabase, temuan saat pengerjaan, dan
masalah yang masih perlu ditangani.

## Status saat ini

| Item | Status |
|---|---|
| Project `kasir-skanilan2` dibuat | ✅ |
| 9 tabel awal dibuat | ✅ |
| Realtime aktif (5 tabel di publication `supabase_realtime`) | ✅ |
| Storage bucket `firmware` (private) | ✅ |
| Kredensial API di `.env` | ✅ (anon + publishable + secret terverifikasi) |
| Koneksi PostgreSQL dari mesin dev | ⚠️ **belum berhasil** — lihat [Masalah Koneksi](#masalah-koneksi) |
| 11 tabel aplikasi (`transaction_items`, `settings`, `sessions`, `cache`, `cache_locks`, `personal_access_tokens`) | ⏳ belum dibuat |

> ⚠️ **Penting:** yang tersimpan di Supabase saat ini baru **9 tabel**, sedangkan
> aplikasi butuh **11 tabel**. Enam tabel berikut belum ada:
>
> | Tabel | Dipakai oleh |
> |---|---|
> | `transaction_items` | rincian item transaksi (Ticket 08) |
> | `settings` | pengaturan aplikasi (Ticket 12) |
> | `sessions` | `SESSION_DRIVER=database` |
> | `cache`, `cache_locks` | `CACHE_STORE=database` |
> | `personal_access_tokens` | autentikasi Sanctum untuk Cashier Web UI |
>
> Tanpa itu, aplikasi akan gagal saat dipakai di Supabase.

## Kenapa `database/supabase/schema.sql` tidak dipakai lagi

Awalnya proyek punya file `database/supabase/schema.sql` berisi DDL manual.
File itu **ternyapi out of sync** dengan migration Laravel:

- Tidak punya `transaction_items`, `settings`, `sessions`, `cache`,
  `cache_locks`, `personal_access_tokens`
- `transactions` kehilangan kolom `receipt_no`, `tap_idempotency_key`,
  `expires_at`, `synced_at`
- `console_credentials` kehilangan `api_key`, `rotated_at`
- `audit_logs` kehilangan `actor_id`, `ip_address`
- `menu_items.stock` default `0` padahal aplikasi memakai `-1` sebagai unlimited

Menambal manual akan menghasilkan drift lagi di ticket berikutnya.

**Keputusan: sumber kebenaran tunggal adalah `php artisan migrate`.**
`database/supabase/schema.sql` sekarang hanya berisi bagian yang **tidak**
bisa dibuat migration, yaitu:

- pendaftaran tabel ke publication `supabase_realtime`
- pembuatan storage bucket `firmware`
- kebijakan RLS

## Cara menerapkan schema

### Langkah 1 — Reset schema publik

Jalankan di **Supabase Dashboard → SQL Editor**:

```sql
DROP SCHEMA public CASCADE;
CREATE SCHEMA public;
GRANT ALL ON SCHEMA public TO postgres;
GRANT ALL ON SCHEMA public TO anon;
GRANT ALL ON SCHEMA public TO authenticated;
```

> ⚠️ Menghapus semua data di schema `public`. Aman karena isinya baru dibuat
> hari ini dan belum dipakai.

### Langkah 2 — Jalankan migration Laravel

```bash
php artisan migrate --force
```

Laravel akan membuat ke-15 tabel beserta foreign key dan index-nya, persis
sama dengan yang dipakai di development SQLite.

### Langkah 3 — Realtime + bucket + RLS

Jalankan isi `database/supabase/schema.sql` (hanya bagian 5 dan 6).

### Langkah 4 — Seed data awal

Minimal perlu satu admin:

```sql
INSERT INTO users (id, name, email, password, role, balance_cents, created_at, updated_at)
VALUES (
  gen_random_uuid(),
  'Admin Kasir',
  'admin@kasir.local',
  '$2y$12$...',            -- hash dari `php artisan tinker` => Hash::make('password123')
  'admin',
  0,
  now(), now()
);
```

Untuk hash-nya:

```bash
php artisan tinker --execute="echo Hash::make('password123');"
```

Atau lebih mudah, jalankan seeder lewat aplikasi setelah migration:
`php artisan db:seed --class=AdminUserSeeder`.

## Verifikasi

```sql
-- 1. Semua tabel aplikasi harus ada (harus 15)
SELECT count(*) AS tabel FROM information_schema.tables
WHERE table_schema = 'public'
  AND table_name IN (
    'users','rfid_cards','consoles','console_credentials','categories',
    'menu_items','transactions','transaction_items','audit_logs',
    'password_resets','settings','sessions','cache','cache_locks',
    'personal_access_tokens'
  );

-- 2. Realtime (harus 5)
SELECT tablename FROM pg_publication_tables WHERE pubname = 'supabase_realtime';

-- 3. Bucket (harus 1 baris, public = false)
SELECT id, name, public FROM storage.buckets WHERE id = 'firmware';

-- 4. Kolom yang sempat hilang di schema.sql
SELECT column_name FROM information_schema.columns
WHERE table_name = 'console_credentials' AND column_name IN ('api_key','rotated_at');
```

## Beralih ke Supabase di aplikasi

Edit `.env`:

```dotenv
DB_CONNECTION=pgsql
DB_HOST=<dari Dashboard → Database → Connection → URI>
DB_PORT=5432
DB_DATABASE=postgres
DB_USERNAME=postgres.<project-ref>

# WAJIB diapit tanda kutip kalau password diawali "#"
DB_PASSWORD="..."

SUPABASE_URL=https://<project-ref>.supabase.co
SUPABASE_ANON_KEY=...
SUPABASE_SERVICE_ROLE_KEY=...
```

Lalu:

```bash
php artisan config:clear
php artisan migrate
php artisan serve
```

## Masalah Koneksi

Pengembangan lokal memakai SQLite. Percobaan menghubungkan langsung ke
PostgreSQL Supabase gagal dengan dua masalah berbeda:

### 1. Host database tidak punya IPv4

```
db.<project-ref>.supabase.co  →  hanya AAAA record (IPv6)
```

Kalau jaringan tidak mendukung IPv6, host itu tidak bisa dijangkau. Solusinya
memakai **Connection Pooler (Supavisor)**:

```
aws-0-<region>.pooler.supabase.com     port 5432 (session)
aws-0-<region>.pooler.supabase.com     port 6543 (transaction)
username: postgres.<project-ref>
```

### 2. Pooler menolak koneksi

```
FATAL: (ENOTFOUND) tenant/user postgres.<project-ref> not found
```

Artinya prefix region pada hostname pooler tidak cocok dengan region project.
`aws-0-ap-southeast-1.pooler.supabase.com` dijangkau (TCP connect berhasil),
tetapi tenant tidak ditemukan — kemungkinan besar project dibuat di region
selain `ap-southeast-1`.

**Cara memastikan:** Dashboard → **Database → Connection** → salin URI dari
tab **Session Pooler**. String itu sudah memuat host, port, dan username yang
benar.

## Catatan operasional

| Topik | Keterangan |
|---|---|
| `SESSION_DRIVER` | Wajib `database`. Supabase tidak punya filesystem persisten, session di file akan hilang tiap deploy. |
| `CACHE_STORE` | Wajib `database`. Sama alasannya. |
| Password diawali `#` | Wajib `"..."` di `.env`, kalau tidak nilainya terbaca kosong. |
| CA certificate | PHP lokal butuh `curl.cainfo` untuk akses HTTPS ke Supabase. |
| API key baru | Supabase sekarang memakai `sb_publishable_` dan `sb_secret_`. Keduanya valid; `sb_secret_` menggantikan `service_role`. |