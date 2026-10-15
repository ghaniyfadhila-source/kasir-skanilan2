# Ticket 08: Transaction API + Idempotency (Order + RFID Tap)

**Status:** ✅ DONE — branch `ticket-08-transaction-api`

## Objective
REST API untuk order kasir dan tap RFID hardware, dengan pemotongan saldo yang atomik dan idempotent.

## Yang dikerjakan

### Endpoint hardware (middleware `console.auth` = `X-Device-ID` + `X-API-Key`)
| Method | Path | Fungsi |
|---|---|---|
| POST | `/api/console/rfid-tap` | Pembayaran: tap kartu → potong saldo → order selesai |
| GET | `/api/console/balance/{uid}` | Cek sisa saldo (untuk LCD), read-only |
| POST | `/api/console/heartbeat` | Status perangkat (ip, rssi, firmware) |

`POST /api/console/sync` **belum dibuat** — itu bagian Ticket 10 (offline queue).

### Endpoint kasir (Ticket 05, diperbarui)
| Method | Path | Perubahan |
|---|---|---|
| POST | `/api/cashier/orders` | Tambah `expires_at`, konsistenkan duplicate response |
| GET | `/api/cashier/orders/{id}/status` | Kirim `receipt_no`, `expires_at`, alasan void |
| POST | `/api/cashier/orders/{id}/cancel` | 422 + `error_code` (bukan 404), isi `voided_at`/`voided_by` + audit log |

### Komponen baru
- `App\Services\RfidTapService` — logika settle transaksi, dipisah dari HTTP supaya bisa dipakai scheduler/test/Realtime
- `App\Http\Middleware\ConsoleAuth` — validasi device, `hash_equals` anti timing attack
- `App\Console\Commands\VoidExpiredOrders` — `orders:void-expired`, dijadwalkan tiap menit
- `App\Console\Commands\ShowConsoleCredentials` — `console:credentials` untuk ambil api_key saat flash ke ESP8266
- Migration: `transactions.receipt_no`, `transactions.tap_idempotency_key`, `transactions.expires_at`

### Model `Transaction`
Konstanta status (`awaiting_rfid`, `completed`, `voided`, `pending_sync`), scope
`awaitingRfid()`/`expired()`, helper `canBePaidByTap()`, dan
`generateReceiptNo()` → `TXN-YYYYMMDD-NNNN`.

## Acceptance Criteria
- [x] Order concurrent dengan `idempotency_key` sama → hanya 1 dibuat (unique index)
- [x] Tap pada order `completed`/`voided` → 422 `order_not_tappable`
- [x] Saldo kurang → 422 `insufficient_balance`, order tetap `awaiting_rfid`
- [x] RFID tidak valid/dicabut → 404 `card_invalid`
- [x] Stok dicek saat tap (cegah overselling)
- [x] Order kedaluwarsa > 60 detik → auto-void oleh scheduler
- [x] Semua nominal dalam sen (integer)
- [x] Audit log per transaksi (`transaction.completed`, `transaction.voided_timeout`, `transaction.voided_by_cashier`)
- [x] Idempotensi terpisah untuk order (`idempotency_key`) dan tap (`tap_idempotency_key`)

## Keamanan
- API key dibandingkan dengan `hash_equals` (anti timing attack)
- Device tidak dikenal / nonaktif / API key salah → 401 dengan pesan generik
- Tap hanya bisa memproses order milik console yang sama
- `lockForUpdate()` pada baris pembeli mencegah dua tap bersamaan sama-sama memotong saldo

## Bug yang ditemukan & diperbaiki
| Lokasi | Masalah |
|---|---|
| `menu_items.stock` migration | Default `0` dengan komentar "0 = unlimited", padahal seluruh kode memakai `-1` sebagai unlimited dan `menu()` memfilter `stock != 0` → **semua menu item default tidak pernah muncul di Cashier Web UI**. Diubah ke `default(-1)` sesuai SPEC §menu_items |
| `CashierMiddleware` | Console di-`merge()` ke input request, bocor ke `$request->all()`/log. Diganti ke request *attributes* + accessor `Controller::console()` |
| `Transaction::generateReceiptNo()` | Versi pertama menghitung urutan dengan `whereDate` + `orWhereRaw` yang rancu. Diganti pola `receipt_no LIKE 'TXN-{Ymd}-%'` yang sederhana & benar |
| `OrderController::cancel()` | Order `completed` membalas 404 (menyesatkan, order-nya ada). Sekarang 422 `order_not_cancellable` |
| `VoidExpiredOrders` | Versi pertama memakai `canBePaidByTap()` sebagai guard — nilainya **false** untuk order kedaluwarsa, justru membatalkan targetnya sendiri. Diganti `isAwaitingRfid()` |

## Testing
- `tests/Feature/RfidTapServiceTest.php` — 17 test (logic settle)
- `tests/Feature/ConsoleApiTest.php` — 15 test (HTTP + middleware)
- `tests/Feature/VoidExpiredOrdersTest.php` — 7 test (auto-void)
- Total suite: **139 test lulus, 438 assertion**
- Verifikasi manual end-to-end via HTTP: 28 check lulus (login → menu → order → tap → struk → idempotensi → auth → heartbeat)

## Catatan untuk Ticket 07 (firmware)
Credential perangkat diambil dengan:
```bash
php artisan console:credentials ESP-AABBCCDDEEFF
```
Header yang dikirim ESP8266: `X-Device-ID` dan `X-API-Key`.

## Yang belum (pindah ke ticket lain)
- Realtime broadcast (`console:{device_id}`, `cashier:{device_id}`) → **Ticket 09**
- `POST /api/console/sync` + antrean offline → **Ticket 10**