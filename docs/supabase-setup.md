# Setup Supabase - Kasir Skanilan 2

Dokumen ini adalah prosedur setup Supabase. Yang paling penting: **hampir
semua langkah bisa dicek dengan satu perintah** di akhir.

```bash
php artisan supabase:check
```

Kalau perintahnya keluar dengan kode 0, setup sudah benar. Kalau tidak, outputnya
menyebutkan apa yang salah dan cara memperbaikinya.

## Alur singkat

```bash
# 1. Buat project di Supabase dengan region yang benar (region tidak bisa diubah
#    sesudahnya).

# 2. Pastikan region-nya benar. Perintah ini mendeteksi sendiri, tanpa perlu
#    password database:
php artisan supabase:detect-region

# 3. Tulis konfigurasi koneksi ke .env
php artisan supabase:configure --url=https://<ref>.supabase.co --region=ap-southeast-1 --password="<password>"

#    Alternatif, kalau mau menempel URI dari Dashboard:
#    php artisan supabase:configure --uri="postgresql://..."

# 4. Salin kunci API dari Dashboard > Project Settings > API ke .env
#    (SUPABASE_ANON_KEY, SUPABASE_PUBLISHABLE_KEY, SUPABASE_SERVICE_ROLE_KEY,
#     SUPABASE_SECRET_KEY)

# 5. Periksa
php artisan supabase:check

# 6. Isi data awal
php artisan db:seed --database=supabase --force

# 7. Beralih ke Supabase di aplikasi (ubah di .env)
#    DB_CONNECTION=supabase
#    lalu: php artisan config:clear

# 8. Pastikan aplikasinya benar-benar bisa dipakai
php artisan serve --port=8010
php artisan cashier:smoke --topup

# 9. Periksa lagi
php artisan supabase:check
```

## Beralih ke Supabase

Koneksi `supabase` terpisah dari koneksi utama, jadi keduanya bisa hidup
berdampingan:

| `.env` | Dipakai untuk |
|---|---|
| `DB_CONNECTION=sqlite` | pengembangan lokal, cepat, tanpa jaringan |
| `DB_CONNECTION=supabase` | instalasi sungguhan, database di Supabase |

Setelah `php artisan config:clear`, perintah berikut membaca blok
`SUPABASE_DB_*` dan otomatis ikut memakai Supabase.

## Dua verifikasi yang wajib dijalankan

`php artisan supabase:check` memeriksa konfigurasi dan keamanan. Tapi ada dua
kelemahan yang tidak bisa menangkapnya:

### Tipe kolom id

SQLite **tidak menegakkan tipe kolom**. Kolom `bigint` yang sebenarnya menampung
UUID lolos tanpa error selama pengembangan lokal, lalu meledak di PostgreSQL:

```
SQLSTATE[22P02]: invalid input syntax for type bigint: "a2ff254c-660e-4485-af4a-1fb22f5e31f9"
```

Gejalanya muncul saat login, karena Laravel menulis id user ke
`sessions.user_id`. Semua model aplikasi memakai primary key UUID, jadi setiap
kolom yang menyimpan id harus UUID.

`supabase:check` punya bagian **Tipe kolom id** yang membandingkan tipe kolom di
PostgreSQL dengan konvensi UUID. Bagian ini pernah menemukan dua bug sekaligus:
`sessions.user_id` dan `personal_access_tokens.tokenable_id`.

### Alur kasir menyeluruh

Halaman login yang terbuka tidak berarti aplikasinya bisa dipakai. Yang penting
adalah satu alur penuh:

```
kasir login -> pilih menu -> buat order -> ESP8266 melihat order
-> pembeli tap kartu -> transaksi selesai -> saldo berkurang -> kasir melihat struk
```

```bash
php artisan serve --port=8010     # di terminal terpisah
php artisan cashier:smoke --topup
```

Perintah ini melewati semuanya lewat HTTP, jadi juga menguji RLS, tipe kolom,
dan konfigurasi rute yang tidak disentuh test suite.

Opsi `--topup` menambah saldo pembeli lebih dulu supaya perintah bisa dijalankan
berulang-ulang; tanpa opsi itu, saldo akan habis setelah beberapa kali jalan.

## Mendeteksi region otomatis

Region Supabase **tidak bisa diubah** setelah project dibuat, dan kesalahan region
menghasilkan pesan yang sama sekali tidak membantu:

```
FATAL: (ENOTFOUND) tenant/user postgres.<ref> not found
```

Pesan itu tidak menyebut region sama sekali, padahal itu penyebabnya.

`php artisan supabase:detect-region` memperjelasnya dengan memanfaatkan urutan
pemeriksaan di connection pooler: pooler mengecek **tenant lebih dulu**, sebelum
memvalidasi password. Jadi tanpa password yang benar pun, galatnya berbeda:

| Galat | Arti |
|---|---|
| `tenant/user ... not found` | project ini tidak ada di region itu |
| `password authentication failed` | **region benar**, hanya password-nya yang salah |

Perintah mencoba seluruh region Supabase dan berhenti di yang cocok. Password yang
dipakai sengaja ngawur dan tidak pernah berhasil masuk.

Untuk pengguna di Indonesia, urutannya:

| Region | Kota | Catatan |
|---|---|---|
| `ap-southeast-1` | Singapore | Paling dekat, latency paling rendah |
| `ap-southeast-2` | Sydney | |
| `ap-northeast-1` | Tokyo | |
| `ap-east-1` | Hong Kong | |
| `ap-south-1` | Mumbai | |

Kalau region salah pilih, satu-satunya jalan adalah membuat project baru.

## Kenapa `database/supabase/schema.sql` tidak dipakai untuk tabel

Awalnya proyek punya file DDL manual berisi seluruh tabel. File itu **ternyata
out of sync** dengan migration Laravel:

| Yang salah di schema.sql | Akibatnya |
|---|---|
| Tidak ada `transaction_items`, `settings`, `sessions`, `cache`, `cache_locks`, `personal_access_tokens` | Aplikasi gagal total, karena butuh 15 tabel |
| `transactions` kehilangan `receipt_no`, `tap_idempotency_key`, `expires_at`, `synced_at` | Nomor struk dan idempotensi tap tidak berfungsi |
| `console_credentials` kehilangan `api_key`, `rotated_at` | Rotasi credential konsol tidak berfungsi |
| `audit_logs` kehilangan `actor_id`, `ip_address` | Jejak audit tidak lengkap |
| `menu_items.stock` default `0`, aplikasi memakai `-1` | Stok tak terbatas jadi salah baca |

Menambal manual seperti itu akan menghasilkan drift lagi di ticket berikutnya.

**Keputusan: sumber kebenaran tunggal adalah `php artisan migrate`.**
`database/supabase/schema.sql` sekarang hanya berisi bagian yang tidak bisa
dibuat migration:

- pendaftaran tabel ke publication `supabase_realtime`
- pembuatan storage bucket `firmware`
- kebijakan RLS

`php artisan supabase:check` juga membaca daftar tabel yang diharapkan dari
file migration, sehingga tabel baru di ticket berikutnya otomatis ikut dicek.

## Host pooler per region

Nama host connection pooler mengikuti region, jadi region yang salah akan membuat
`tenant/user not found`:

```
aws-0-ap-southeast-1.pooler.supabase.com   Singapore
aws-0-ap-southeast-2.pooler.supabase.com   Sydney
aws-0-ap-northeast-1.pooler.supabase.com  Tokyo
...
```

Cara paling akurat: **Dashboard > Database > Connection > tab "Session Pooler"**,
lalu salin URI-nya. String itu sudah memuat host, port, dan username yang benar
untuk region project kamu.

## Kenapa harus lewat connection pooler

Host direct `db.<ref>.supabase.co` **hanya punya AAAA record (IPv6)**. Di jaringan
tanpa IPv6, host itu tidak bisa di-resolve sama sekali dan error-nya
`could not translate host name` - yang tidak menyiratkan IPv6 sebagai penyebabnya.

Connection pooler punya endpoint IPv4, jadi bisa dipakai dari mana saja:

| Port | Mode | Cocok untuk |
|---|---|---|
| 5432 | session pooler | aplikasi yang lama hidup, `migrate`, `DB::transaction()` |
| 6543 | transaction pooler | serverless yang koneksi singkat |

Proyek ini memakai session pooler (5432) karena `RfidTapService` memakai
`lockForUpdate()` di dalam transaksi.

## Dua jebakan `.env` yang pernah menggigit

### 1. Password berawalan `#`

```dotenv
DB_PASSWORD=#rahasia          # SALAH: dibaca sebagai komentar, jadi kosong
DB_PASSWORD="#rahasia"        # BENAR
```

Tidak ada error yang muncul; autentikasi hanya gagal belakangan dengan pesan yang
tidak menjelaskan penyebabnya. `php artisan supabase:configure` sudah otomatis
mengapit nilai yang perlu tanda kutip.

### 2. Kunci API terpotong atau tertukar

Kunci JWT panjang dan mudah salah ketik. Gejalanya `Invalid API key` padahal
project-nya sehat. `supabase:check` membaca claim `ref` di dalam JWT dan
membandingkannya dengan project ref dari URL, jadi kunci dari project lain
terdeteksi sebelum dites ke server.

Dua format kunci yang dipakai Supabase:

| Format | Awalan | Untuk |
|---|---|---|
| lama (JWT) | `eyJ...` | ada claim `role`: `anon` atau `service_role` |
| baru | `sb_publishable_` / `sb_secret_` | publishable untuk browser, secret untuk server |

`sb_secret_` adalah pengganti `service_role`. Keduanya bisa diisi.

## Koneksi `supabase` terpisah

Konfigurasi database Supabase disimpan di blok `SUPABASE_DB_*`, bukan `DB_*`.
Aplikasi lokal tetap memakai SQLite, dan beralih ke Supabase cukup dengan:

```dotenv
DB_CONNECTION=supabase
```

Konsekuensinya, `php artisan supabase:check` bisa memeriksa Supabase tanpa
mengubah koneksi yang dipakai aplikasi - berguna karena jaringan mesin dev
kadang tidak bisa menjangkau Supabase sama sekali.

## Verifikasi manual

Kalau lebih suka cek langsung lewat SQL Editor:

```sql
-- 1. Tabel aplikasi, harus 15
SELECT count(*) AS tabel FROM information_schema.tables
WHERE table_schema = 'public'
  AND table_name IN (
    'users','rfid_cards','consoles','console_credentials','categories',
    'menu_items','transactions','transaction_items','audit_logs',
    'password_resets','settings','sessions','cache','cache_locks',
    'personal_access_tokens'
  );

-- 2. Realtime
SELECT tablename FROM pg_publication_tables WHERE pubname = 'supabase_realtime';

-- 3. Bucket harus private
SELECT id, public FROM storage.buckets WHERE id = 'firmware';

-- 4. Kolom yang sempat hilang di schema.sql
SELECT column_name FROM information_schema.columns
WHERE table_name = 'console_credentials' AND column_name IN ('api_key','rotated_at');
```

## Catatan operasional

| Topik | Keterangan |
|---|---|
| `SESSION_DRIVER` | Wajib `database`. Supabase tidak punya filesystem persisten; session di file hilang tiap deploy. |
| `CACHE_STORE` | Wajib `database`, alasan sama. |
| CA certificate | PHP lokal butuh `curl.cainfo` untuk HTTPS ke Supabase. |
| Bucket `firmware` | Wajib private. Firmware hanya lewat signed URL berumur pendek. |
| Reset password | Mengganti password database di Dashboard langsung membatalkan nilai di `.env`, jadi `.env` harus diperbarui. |