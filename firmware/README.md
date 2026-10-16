# Firmware Kasir — Kasir Skanilan 2

Firmware untuk perangkat kasir ESP8266 (NodeMCU v3) dengan pembaca RFID MFRC522
dan LCD I2C 1602/2004.

## Ringkasan

| Spec | Nilai |
|---|---|
| Platform | ESP8266 (NodeMCU v3 / `nodemcuv2`) |
| Framework | Arduino via PlatformIO |
| Filesystem | LittleFS |
| Memakai RAM | 38.7% (31.680 / 81.920 byte) |
| Memakai Flash | 33.7% (352.419 / 1.044.464 byte) |

Batas acceptance criteria ticket: RAM < 80%, Flash < 70%. Keduanya terpenuhi
dengan jarak yang jauh.

## Alur Kerja

```
boot
 └─ WiFi connect
     ├─ gagal → mode setup (captive portal 192.168.4.1)
     └─ sukses
         └─ heartbeat ke backend
             └─ polling order yang menunggu tap
                 └─ LCD: "TAP RFID  Rp 15.000"
                     └─ pembeli tap kartu
                         ├─ online  → POST /api/console/rfid-tap
                         │             ├─ sukses → struk + saldo baru, beep 2x
                         │             ├─ saldo kurang → beep panjang
                         │             ├─ kartu tidak valid → beep pendek
                         │             └─ order habis → "DIBATALKAN"
                         └─ offline → simpan ke LittleFS
                                       └─ kirim ulang saat online
                                       └─ tap > 5 menit → auto void
```

## Build

```bash
cd firmware

pio run                      # semua environment
pio run -e nodemcuv2_ota     # NodeMCU v3 dengan OTA (app 1MB)
pio run -e nodemcuv2         # NodeMCU v3 tanpa OTA (app 3MB)
pio run -e nodemcuv2 -t upload    # flash firmware
pio run -e nodemcuv2_ota -t uploadfs  # flash partisi LittleFS
pio device monitor           # serial monitor 115200
```

### Partisi

| File | Layout | Dipakai untuk |
|---|---|---|
| `partitions_ota.csv` | 1MB app0 + 1MB app1 + 1MB LittleFS | `nodemcuv2_ota` (default) |
| `partitions.csv` | 3MB app + 1MB LittleFS | `nodemcuv2` (firmware kebesaran) |

## Konfigurasi Awal

Firmware butuh dua nilai yang tidak bisa ditebak: **api_key** dan **server_url**.

### 1. Dapatkan kredensial perangkat dari backend

```bash
php artisan console:credentials ESP-AABBCCDDEEFF
```

Perintah itu mencetak `device_id` dan `api_key`. `api_key` **tidak boleh**
dimasukkan ke git.

### 2. Tulis `config.json` di LittleFS

Buat file `firmware/data/config.json` sebelum upload:

```json
{
  "device_id": "ESP-AABBCCDDEEFF",
  "api_key": "paste-dari-artisan-console-credentials",
  "server_url": "http://192.168.1.10:8000",
  "wifi_ssid": "NamaWifiSekolah",
  "wifi_password": "passwordwifi",
  "cashier_password": "",
  "order_poll_ms": 1500
}
```

`data/config.json` sudah masuk `.gitignore`. Lihat `.gitignore` di folder firmware.

### 3. Upload

```bash
pio run -e nodemcuv2_ota -t uploadfs   # partisi + config
pio run -e nodemcuv2_ota -t upload     # firmware
```

`device_id` boleh dikosongkan — akan otomatis diturunkan dari MAC address
(format `ESP-` + 6 byte terakhir, uppercase).

## Wiring

| Fungsi | Pin ESP8266 | Catatan |
|---|---|---|
| SDA (I2C) | D2 (GPIO4) | LCD |
| SCL (I2C) | D1 (GPIO5) | LCD **dan** buzzer — berbagi pin |
| RST RC522 | D0 (GPIO16) | |
| IRQ RC522 | D3 (GPIO0) | tidak dipakai |
| MISO RC522 | D7 (GPIO14) | SPI |
| MOSI RC522 | D6 (GPIO12) | SPI |
| SCK RC522 | D5 (GPIO14) | SPI |
| SS RC522 | D8 (GPIO15) | |
| Buzzer | D1 (GPIO5) | aktif LOW pada banyak modul |
| LED hijau | D4 (GPIO2) | built-in |
| LED merah | D3 (GPIO0) | |

> ⚠️ D1 dipakai bersama SCL LCD dan buzzer. Gunakan buzzer *passif* (butuh
> sinyal PWM), bukan buzzer aktif. Buzzer aktif akan menarik D1 ke ground dan
> membuat LCD berkedip.

## Endpoint yang Dipakai

Semua sudah tersedia dari Ticket 08 — firmware tidak butuh Realtime untuk alur inti.

| Method | Path | Kapan dipakai |
|---|---|---|
| POST | `/api/console/heartbeat` | tiap 30 detik |
| GET | `/api/console/pending-order` | tiap 1.5 detik |
| POST | `/api/console/rfid-tap` | saat kartu ditap |
| GET | `/api/console/balance/{uid}` | belum dipakai di loop utama |

Header wajib: `X-Device-ID` dan `X-API-Key`.

## Antrean Offline

- File: `/queue/taps.jsonl` (JSON Lines di LittleFS)
- Batas: 500 KB, tolak tap baru di atas 90%
- Auto-void: tap lebih tua dari **5 menit** ditandai `voided`
- Maksimal mencoba kirim ulang tiap 5 detik

Record dihapus dari antrean kalau server menjawab dengan alasan yang jelas
(sukses, duplikat, saldo kurang, order sudah diproses). Kalau jawabannya kosong
(network putus), `retry_count` naik dan record dicoba lagi.

## Struktur File

| File | Isi |
|---|---|
| `src/main.cpp` | setup, loop utama, alur tap |
| `src/config.cpp` | baca/tulis `config.json`, device_id dari MAC |
| `src/state.cpp` | enum state + label untuk LCD |
| `src/lcd.cpp` | driver PCF8574, tulis 2 baris sekaligus |
| `src/rfid.cpp` | driver MFRC522, poll non-blocking |
| `src/buzzer.cpp` | pola nada tanpa blocking |
| `src/led.cpp` | LED status |
| `src/network.cpp` | WiFi + klien REST |
| `src/queue.cpp` | antrean LittleFS + auto-void |

## Yang Belum Selesai

| Item | Kenapa | Ticket |
|---|---|---|
| Realtime WebSocket (push `awaiting_rfid`) | Butuh Supabase project | 09 |
| OTA update | Perlu bucket `firmware` + perangkat fisik | 14 |
| `queue.clear` dari admin | Butuh channel Realtime | 09 |
| Pengisian WiFi via portal | Versi sekarang hanya menampilkan AP, belum ada form | — |

Order yang menunggu tap saat ini ditemukan lewat polling
`GET /api/console/pending-order` setiap 1.5 detik. Setelah Realtime aktif,
polling ini bisa diganti dengan push supaya LCD langsung berubah.

## Verifikasi

- `pio run` sukses untuk `nodemcuv2`, `nodemcuv2_ota`
- Memori: RAM 38.7%, Flash 33.7%
- Perlu **perangkat fisik** untuk uji end-to-end (baca UID kartu, respons LCD,
  nada buzzer). Simulasi otomatis dengan Wokwi belum disiapkan — lihat
  environment `wokwi` di `platformio.ini` sebagai titik awal.