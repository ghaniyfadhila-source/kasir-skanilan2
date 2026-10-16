# Ticket 07: ESP8266 Firmware (PlatformIO)

**Status:** ✅ DONE — branch `ticket-07-firmware`, commit `a696d57`

## Yang dikerjakan

Project PlatformIO di `firmware/`:

| File | Isi |
|---|---|
| `src/main.cpp` | setup, loop 100 ms, alur tap, debounce anti-spam |
| `src/config.cpp` | `config.json` di LittleFS, device_id dari MAC |
| `src/state.cpp` | enum state + label LCD |
| `src/lcd.cpp` | driver PCF8574, tulis 2 baris sekaligus (anti flicker) |
| `src/rfid.cpp` | driver MFRC522, poll non-blocking |
| `src/buzzer.cpp` | pola nada non-blocking |
| `src/led.cpp` | LED status + kedip saat busy |
| `src/network.cpp` | WiFi + klien REST |
| `src/queue.cpp` | antrean JSON Lines + auto-void |
| `platformio.ini`, `partitions*.csv`, `diagram.json`, `README.md` | konfigurasi & dokumentasi |

## Acceptance Criteria

- [x] Firmware compile: `pio run` sukses (`nodemcuv2` & `nodemcuv2_ota`)
- [ ] Device registers with backend — butuh perangkat fisik
- [x] RFID tap → kirim UID → terima hasil → LCD terupdate (alur kode ada; uji nyata butuh hardware)
- [x] Offline queue bertahan lintas reboot (JSON Lines di LittleFS, dimuat saat boot)
- [x] Auto-void > 5 menit (diimplementasikan, belum diuji di hardware)
- [ ] OTA — pindah ke Ticket 14 (butuh Supabase Storage)
- [x] **RAM 38.7%** (batas < 80%), **Flash 33.7%** (batas < 70%)

## Endpoint backend yang ditambahkan

`GET /api/console/pending-order`

Perangkat sebelumnya tidak punya cara tahu order mana yang menunggu tap —
notifikasi push-nya memang duties Realtime (Ticket 09). Tanpa endpoint ini,
LCD tidak bisa menampilkan "TAP RFID Rp X" sama sekali. Endpoint ini memakai
auth `console.auth` yang sama seperti endpoint hardware lain.

Ditutup dengan 5 test di `tests/Feature/ConsoleApiTest.php`.

## Kenapa pakai REST, bukan Realtime

Ticket 08 sudah menyediakan `heartbeat`, `balance/{uid}`, dan `rfid-tap` lewat
REST. Alur inti kasir (tap → potong saldo → struk) jadi berfungsi **tanpa**
Supabase project. Yang tertinggal hanya *push* Realtime, yang menurut ticket
saja boleh diganti polling REST bila terlalu berat untuk ESP8266.

Setelah Realtime aktif (Ticket 09), polling `pending-order` tiap 1.5 detik bisa
diganti push supaya LCD berubah seketika.

## Library

- **ArduinoJson 7** — serialisasi JSON (satu `JsonDocument` per fungsi)
- **MFRC522 1.4.x** — paket registry `miguelbalboa/MFRC522`
- **ESP8266WiFi / ESP8266HTTPClient / LittleFS** — sudah ikut bundle platform

> Penting: `miguelbalboa/rfid` 4.x (API modern) **tidak ada** di registry
> PlatformIO, dan clone dari GitHub diblokir di lingkungan build ini. Paket
> registry memakai API bergaya `PCD_`/`PICC_` yang berbeda; driver ditulis
> mengikuti API yang benar-benar terpasang.

## Yang belum (pindah ke ticket lain)

| Item | Ticket | Alasan |
|---|---|---|
| Realtime WebSocket (push `awaiting_rfid`) | 09 | Butuh Supabase project |
| OTA update | 14 | Butuh Supabase Storage bucket + hardware |
| `queue.clear` dari admin | 09 | Butuh channel Realtime |
| Form WiFi di captive portal | — | Versi sekarang hanya menyalakan AP |

## Verifikasi

- `pio run` sukses untuk **kedua** environment
- RAM **38.7%** (31.680 / 81.920 byte), Flash **33.7%** (352.419 / 1.044.464 byte)
- 5 test backend untuk `pending-order`
- Total suite PHP: **222 test lulus, 697 assertion**
- `.pio/` dan `firmware/data/config.json` sudah di-gitignore agar `api_key`
  hasil `php artisan console:credentials` tidak bocor ke repository
- Diperiksa: tidak ada karakter non-ASCII atau api_key yang tersesat di source

## Catatan hardware

- **D1 dipakai bersama** SCL LCD dan buzzer → wajib pakai buzzer **pasif**
  (butuh PWM). Buzzer aktif akan menarik D1 ke ground dan membuat LCD berkedip.
- Uji end-to-end (UID terbaca, LCD, nada buzzer) belum bisa dijalankan tanpa
  NodeMCU + RC522 + LCD fisik.