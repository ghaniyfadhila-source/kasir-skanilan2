# Firmware Kasir — Kasir Skanilan 2

Firmware untuk perangkat kasir ESP8266 (NodeMCU v3) dengan pembaca RFID MFRC522
dan LCD I2C 1602/2004.

## Ringkasan

| Spec | Nilai |
|---|---|
| Platform | ESP8266 (NodeMCU v3 / `nodemcuv2`) |
| Framework | Arduino via PlatformIO |
| Filesystem | LittleFS |
| Memakai RAM | 39.9% (32.712 / 81.920 byte) |
| Memakai Flash | 34.9% (364.267 / 1.044.464 byte) |

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
                                       └─ kirim ulang saat online (batch 10)
                                       └─ tap > 5 menit → auto void
                                           └─ lapor `status: voided` ke server
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
  "server_url": "http://192.168.1.10:8010",
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

| Method | Path | Kapan dipakai |
|---|---|---|
| POST | `/api/console/heartbeat` | tiap 30 detik; membawa jam server + perintah admin |
| GET | `/api/console/pending-order` | tiap 1.5 detik |
| POST | `/api/console/rfid-tap` | saat kartu ditap (online) |
| POST | `/api/console/sync` | saat online kembali / antrean tersisa (batch 10) |
| POST | `/api/console/commands/ack` | setelah perintah admin dijalankan |
| GET | `/api/console/balance/{uid}` | belum dipakai di loop utama |

Header wajib: `X-Device-ID` dan `X-API-Key`.

## Antrean Offline (Ticket 10)

- File: `/queue/taps.jsonl` (JSON Lines di LittleFS)
- Batas: 500 KB, tap baru ditolak di atas 90%
- Batch: maksimal **10 tap** per `POST /api/console/sync`
- Jeda antar batch 500 ms; setelah koneksi kembali tunggu **2 detik** dulu
- Auto-void: tap lebih tua dari **5 menit** ditandai `voided`
- `retry_count` maksimal **3**; setelah itu record berstatus `failed` dan
  berhenti dicoba otomatis (perlu `queue.clear` dari admin)

### Kapan record dibuang

| Jawaban server | Tindakan |
|---|---|
| `success`, `duplicate` | hapus |
| `void`, `discard` | hapus |
| `insufficient_balance`, `insufficient_stock`, `card_invalid` | hapus (mengulang tidak mengubah hasil) |
| `error` | `retry_count++` |
| tidak ada respons HTTP | **tidak** dihitung retry; dicoba lagi nanti |

### Sumber waktu

Antrean harus bertahan setelah listrik mati, sedangkan `millis()` kembali ke 0
setiap boot. Karena itu umur tap dihitung dari epoch:

1. `server_epoch` pada respons heartbeat/sync (selalu tersedia saat online)
2. SNTP setelah WiFi tersambung
3. epoch terakhir yang disimpan di `/clock.json` (ditulis tiap 5 menit)

Epoch tersimpan selalu sedikit **lebih tua** daripada waktu sebenarnya, jadi
auto-void bisa tertunda tetapi tidak pernah membatalkan tap yang masih baru.
Record dari boot sebelumnya yang umurnya tidak bisa dibuktikan dianggap basi.

### Perintah admin

Firmware tidak memakai WebSocket (heap ESP8266 tidak cukup untuk klien
Phoenix). Perintah `queue.clear` ikut pada respons heartbeat/sync, dijalankan,
lalu di-ack lewat `POST /api/console/commands/ack`. Selama belum di-ack,
perintah dikirim ulang pada heartbeat berikutnya.

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
| `src/network.cpp` | WiFi + klien REST (termasuk sync batch) |
| `src/queue.cpp` | antrean LittleFS + auto-void + retry |
| `src/clock.cpp` | jam dinding (server/NTP/LittleFS) |

## Yang Belum Selesai

| Item | Kenapa | Ticket |
|---|---|---|
| OTA update | Perlu bucket `firmware` + perangkat fisik | 14 |
| Pengisian WiFi via portal | Versi sekarang hanya menampilkan AP, belum ada form | — |
| Uji perangkat fisik | Butuh NodeMCU + RC522 + LCD sungguhan | 15 |

Order yang menunggu tap ditemukan lewat polling
`GET /api/console/pending-order` setiap 1.5 detik. Ini tetap dipakai (bukan
push) karena perangkat tidak berlangganan WebSocket.

## Verifikasi

- `python -m platformio run -e nodemcuv2_ota` sukses
- Memori: RAM 39.9%, Flash 34.9%
- Perlu **perangkat fisik** untuk uji end-to-end (baca UID kartu, respons LCD,
  nada buzzer). Simulasi Wokwi belum diverifikasi.