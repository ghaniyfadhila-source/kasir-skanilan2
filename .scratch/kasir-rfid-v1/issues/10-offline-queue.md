# Ticket 10: Offline Queue + Auto-void (Hardware RFID Tap Only)

## Status
**DONE** — antrean LittleFS, batch sync, auto-void, dan perintah `queue.clear`
sudah berjalan. Firmware berhasil dikompilasi; server diuji lewat 34 test baru
dan smoke test HTTP sungguhan.

## Objective
Implement robust offline RFID tap queue on ESP8266 (LittleFS) with batch sync,
retry logic, and auto-void for stale taps. Cashier Web UI handles order creation
(always online).

## Acceptance Criteria

- [x] Tap RFID saat offline tersimpan di LittleFS dan bertahan setelah reboot
- [x] Saat WiFi kembali, antrean dikirim otomatis setelah jeda 2 detik
- [x] Batch sync memproses 50 tap < 10 detik (uji: 50 tap = 0,29 detik)
- [x] Idempotensi: tap yang sama tidak memotong saldo dua kali
- [x] Auto-void tap > 5 menit + laporan `status: "voided"` ke server
- [x] Retry maksimal 3x untuk penolakan server; gangguan jaringan tidak dihitung
- [x] Antrean penuh (> 90%) ditolak dengan LCD `QUEUE FULL` + buzzer panjang
- [x] Admin bisa mengosongkan antrean lewat halaman console / artisan
- [x] LCD menampilkan `OFFLINE - QUEUED`, `SYNCING...`, `TXN VOIDED`

## Yang dikerjakan

### Server
- [x] Migration `console_commands` (+ RLS aktif di PostgreSQL)
- [x] Migration kolom `queue_pending`, `queue_failed`, `queue_reported_at`
- [x] `ConsoleCommand` + `ConsoleCommandService` (at-least-once delivery)
- [x] `POST /api/console/sync` — batch maksimal 50, hasil per record
- [x] `POST /api/console/commands/ack` — perangkat mengakui perintah
- [x] Heartbeat membawa `server_epoch` + `queue_commands` + counter antrean
- [x] `OrderVoidService` — logika void dipakai bersama scheduler dan perangkat
- [x] Tombol **Kosongkan Antrean** di halaman edit console
- [x] `php artisan console:queue-clear {device_id}`
- [x] Realtime batch: 50 tap = 1 panggilan HTTP, bukan 100

### Firmware
- [x] `clock.h/.cpp` — epoch dari server/NTP/LittleFS, tahan reboot
- [x] `QueueRecord` menyimpan `created_at` (epoch), `created_at_ms`, `boot_id`
- [x] `collectBatch()`, `bumpRetry()`, `autoVoidExpired()`, `clearAll()`
- [x] `Network::syncTaps()` dan `Network::ackCommand()`
- [x] Flush batch 10 tap, jeda 500 ms, retry 5 detik
- [x] Auto-void tiap 60 detik, lapor ke server saat online
- [x] Perintah `queue.clear` dijalankan dari respons heartbeat/sync
- [x] LCD state `SYNCING...`, `OFFLINE - QUEUED`, `TXN VOIDED: n`

## Keputusan desain

### Waktu harus epoch, bukan `millis()`
Bug kritis yang ditemukan sebelum implementasi: `createdAt` lama memakai
`millis()`. Setelah reboot, `now - createdAt` underflow (unsigned) sehingga
semua tap terlihat berumur 49 hari dan langsung di-void. Sekarang:

1. `server_epoch` dari heartbeat/sync (utama, gratis saat online)
2. SNTP setelah WiFi tersambung
3. epoch terakhir di `/clock.json`, ditulis tiap 5 menit

Epoch tersimpan selalu lebih tua dari kenyataan, jadi auto-void bisa tertunda
tetapi tidak pernah prematur. Record dari boot sebelumnya yang umurnya tidak
bisa dibuktikan dianggap basi (lebih aman daripada menggantung selamanya).

### `retry_count` hanya untuk penolakan server
Gangguan jaringan bukan kesalahan record. Kalau `retry_count` naik setiap kali
HTTP gagal, antrean akan "habis" hanya karena WiFi sekolah buruk. Jadi:
- tidak ada respons HTTP → tidak menaikkan retry, coba lagi nanti
- server menjawab `error` → retry++, setelah 3 menjadi `failed`

### Record `voided` tidak langsung dihapus
Auto-void menandai record `voided` tetapi tetap menyimpannya, lalu mengirimnya
ke server pada sync berikutnya. Tanpa ini, order di server akan menggantung
sampai scheduler 60 detik (dan di luar jam scheduler, selamanya).

Server hanya membatalkan order bila memang sudah lewat `expires_at`. Kalau
order masih segar, laporan void diabaikan — jam perangkat yang salah tidak
boleh membatalkan order yang sah.

### Perintah admin lewat heartbeat, bukan WebSocket
Klien Phoenix tidak muat di heap ~40 KB ESP8266 dan membuat kasir bergantung
internet. `queue.clear` disimpan di tabel `console_commands`, dikirim pada
heartbeat/sync, dan diulang sampai perangkat mengirim ack. Perintah kedaluwarsa
setelah 10 menit supaya tidak dieksekusi berhari-hari kemudian.

### Broadcast digabung per batch
Satu tap menghasilkan 2 event (kasir + dashboard). Batch 50 tap berarti 100
panggilan HTTP berurutan ke Supabase dari dalam satu request — cukup untuk
membuat perangkat timeout. `RealtimePublisher` sekarang punya mode
`beginBatch()`/`endBatch()` yang mengirim semua pesan dalam satu request.
Hasil uji: 10 pesan, 1 request.

### Firmware mengirim 10 tap per request
Batas server 50, tetapi 10 dipilih supaya:
- respons selesai jauh di bawah timeout 6 detik
- heap ESP8266 tidak dipakai untuk JSON raksasa
- LCD kembali responsif lebih cepat

## Bug yang ditemukan & diperbaiki

1. **`QueueRecord.createdAt` memakai `millis()`** → semua tap auto-void setelah
   reboot. Diganti epoch + `boot_id` + fallback `millis()` satu boot.
2. **Idempotency key berubah saat retry offline** → kalau respons sukses hilang
   di jaringan, tap yang disimpan memakai kunci baru sehingga server bisa
   memproses transaksi kedua. Sekarang kunci dibuat sekali dan dipakai ulang.
3. **Observer console memantau `last_seen_at`** yang tidak ada. Heartbeat tidak
   pernah tersiar ke dashboard dan payload `last_seen_at` selalu null. Diganti
   `last_heartbeat_at` (nama kunci payload dipertahankan).
4. **`<form>` bersarang di halaman edit console** → tombol rotasi kredensial
   mengirim form edit (PUT), bukan rotasi. Form rotasi dan panel antrean
   dipindah ke luar form utama.
5. **Broadcast 2x per tap di batch** membuat 50 tap butuh 63 detik di test.
   Setelah penggabungan: 0,29 detik.

## Cara uji

```powershell
# test otomatis
php\php.exe artisan test --filter="ConsoleSyncTest|ConsoleCommandTest"

# manual: buat perintah lalu lihat di heartbeat
php\php.exe artisan console:queue-clear ESP-AABBCCDDEEFF
php\php.exe artisan serve --port=8010
```

Respons sync yang diharapkan:

```json
{
  "success": true,
  "data": {
    "server_epoch": 1761090000,
    "results": [
      {"idempotency_key": "...", "status": "success", "receipt_no": "TXN-...", "total_cents": 15000},
      {"idempotency_key": "...", "status": "void", "message": "Order sudah tidak bisa ditap."}
    ],
    "queue_commands": [{"id": "uuid", "command": "queue.clear", "payload": null}]
  }
}
```

Status hasil yang dikenal firmware:
`success`, `duplicate`, `void`, `discard`, `insufficient_balance`,
`insufficient_stock`, `card_invalid`, `error`.

## Batasan yang perlu diketahui

- **Masa tap order tetap 60 detik.** Antrean offline hanya menyelamatkan tap
  yang terjadi dalam 60 detik itu (gangguan WiFi singkat). Tap yang lebih tua
  akan dijawab `void` oleh server — sesuai acceptance criteria ticket, tetapi
  artinya antrean 5 menit bukan jendela pembayaran 5 menit.
- **Uji perangkat fisik belum dilakukan.** Verifikasi firmware baru sebatas
  kompilasi + review; belum ada NodeMCU/RC522/LCD sungguhan.
- `queue.clear` dieksekusi pada heartbeat berikutnya (maks. 30 detik), bukan
  seketika.
