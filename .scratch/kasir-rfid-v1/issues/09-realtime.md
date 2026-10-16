# Ticket 09: Supabase Realtime Integration (3 Channels)

## Status
**DONE** - tiga channel sudah dibangun dan terverifikasi lewat WebSocket sungguhan.

## Yang dikerjakan

### Infrastruktur
- [x] Project Supabase di region `ap-southeast-1` (Singapore)
- [x] Tabel dibuat dari migration, bukan DDL manual
- [x] Row Level Security aktif di seluruh tabel
- [x] 6 tabel terdaftar di publication `supabase_realtime`
- [x] Storage bucket `firmware` dibuat, private

### Layer Realtime
- [x] `RealtimeTopic` - merangkai nama channel, memeriksa konfigurasi
- [x] `RealtimePublisher` - kirim lewat `POST /realtime/v1/api/broadcast`
- [x] 5 peristiwa domain: `OrderPlaced`, `OrderVoided`, `TransactionCompleted`,
      `MenuItemChanged`, `ConsoleStatusChanged`
- [x] Listener menerjemahkan peristiwa menjadi (topik, event, payload)
- [x] Observer untuk perubahan menu dan status konsol

### Channel
- [x] Channel 1: `console:{device_id}` - dokumentasi. Firmware sendiri tidak
      berlangganan WebSocket, lihat catatan di bawah.
- [x] Channel 2: `cashier:{device_id}` - Backend ke Cashier Web UI
- [x] Channel 3: `admin:dashboard` - Backend ke semua browser admin
- [x] Backend broadcasting
- [x] Frontend subscriptions (Alpine.js + Supabase JS)

### Perkakas verifikasi
- [x] `php artisan realtime:listen` - mendengarkan lewat WebSocket
- [x] `php artisan realtime:probe` - mengirim event uji
- [x] `php artisan supabase:check` - ditambah bagian Realtime

## Objective
Integrate Supabase Realtime (WebSocket) for live updates across 3 channels:
console to backend, cashier web UI, admin dashboard.

## Acceptance Criteria

- [x] Perubahan harga menu di admin sampai ke kasir dalam 2 detik
      (`menu.updated` dikirim ke semua konsol aktif)
- [x] Pembayaran lewat tap RFID sampai ke Cashier Web UI
      (`completed`, halaman berpindah ke struk)
- [x] Order dibatalkan atau kedaluwarsa sampai ke kasir (`void`)
- [x] Transaksi baru muncul di dashboard admin tanpa refresh
      (`transaction.created`)
- [x] Perubahan status konsol tampil di dashboard (`console.status`)
- [x] Beberapa channel berjalan bersamaan tanpa salingganggu
- [x] Kunci server tidak pernah dikirim ke browser
- [x] Halaman tetap berfungsi saat WebSocket putus

## Keputusan desain

### Broadcast, bukan postgres_changes
Browser tidak perlu hak baca tabel. RLS tetap ketat tanpa membuka data
transaksi ke klien, dan payload bebas bentuknya.

### Channel dijaga `channel_secret`
Channel broadcast Supabase secara bawaan publik. `REALTIME_CHANNEL_SECRET` di
.env ditambahkan ke nama channel dan hanya dikirim ke halaman yang sudah
terautentikasi. Ini keamanan berbasis penyembunyian, bukan kriptografi; jalur
yang lebih kuat adalah Supabase Auth plus channel privat.

### Firmware tidak berlangganan WebSocket
Klien Phoenix terlalu berat untuk ESP8266 yang punya sekitar 40 KB heap bebas,
dan membuat kasir bergantung pada Supabase berarti kasir mati saat internet
putus - justru skenario yang paling sering terjadi di kantin. Firmware memakai
REST (`/api/console/pending-order`) dan antrean lokal, dikerjakan di Ticket 10.

## Bug yang ditemukan saat pengujian

1. Auto-discovery listener Laravel mendaftarkan listener yang juga didaftarkan
   eksplisit, sehingga setiap event terkirim dua kali tanpa error apa pun.
   Diperbaiki dengan `->withEvents(discover: false)`.
2. Nama accessor salah pada payload (`total_formatted` vs `formatted_total`),
   field terkirim null ke browser.
3. Bingkai WebSocket yang terpotong hilang begitu saja karena sisa buffer
   dibuang tiap kali baca.
4. Partial Blade dibuka dengan blok `<?php` yang tidak ditutup, sehingga HTML
   setelahnya diparse sebagai PHP.

## Testing

Manual, dua terminal:

```
php artisan realtime:listen
php artisan cashier:smoke --topup
```

Otomatis: `php artisan test` (272 test) dan `php artisan supabase:check`.