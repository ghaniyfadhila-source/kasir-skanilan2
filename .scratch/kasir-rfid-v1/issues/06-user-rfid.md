# Ticket 06: User Management + RFID Assign

**Status:** ✅ DONE — commit `7b84923` (branch `ticket-04-menu-crud`)

## Objective
Admin interface for managing users (students, cashiers), RFID card assignment, and balance top-up.

## Scope
- [x] Controller: `Admin\UserController` (resource)
- [x] Routes: `admin.users.*` (index, create, store, show, edit, update, destroy)
- [x] Views:
  - `admin/users/index.blade.php` — Table: Name, Email, Role, Balance, RFID UID, Status, Actions
  - `admin/users/create.blade.php` — Form: name, email, role (student/cashier), password (optional for cashier), initial balance
  - `admin/users/edit.blade.php` — Pre-filled + RFID management section + Balance top-up form
  - `admin/users/show.blade.php` — Detail + transaction history (paginated)
- [x] RFID Management:
  - Section "Kartu RFID" — list assigned cards (UID, label, status, tanggal terbit)
  - Button "Tambah Kartu" — panel inline (bukan modal): input UID manual → creates `rfid_cards` record linked to user
  - Button "Cabut" — sets `revoked_at`, `is_active=false`
  - UID format: 8-char hex, divalidasi lalu dinormalisasi ke huruf besar
  - UID yang sudah dicabut **tetap terpakai** (kartu fisik bisa dipegang pihak lain)
- [x] Balance Top-up:
  - Form: amount (Rupiah), description → menaikkan `balance_cents` + tulis `audit_logs` action `balance.topup`
  - Balance ditampilkan server-side + reaktif via Alpine
  - Hanya untuk role Siswa
- [x] Cashier creation:
  - Role=Cashier wajib memilih console yang belum dipakai kasir lain
  - Password ditampilkan sekali setelah disimpan
  - Menurunkan role Kasir → Siswa otomatis melepas console
- [ ] Student import: CSV upload (name, email, initial_balance) — optional bonus, **tidak dikerjakan**

## Acceptance Criteria
- [x] RFID UID uniqueness enforced (DB unique + validasi form `regex:/^[0-9A-Fa-f]{8}$/`)
- [x] Revoked cards tidak dipakai lagi (diperkuat ticket 08: UID dicabut tidak bisa di-transaksikan)
- [x] Balance top-up membuat jejak audit (`audit_logs`: action `balance.topup`)
- [x] Cashier terikat tepat 1 console (1:1 via `users.device_id` → `consoles.device_id`)
- [x] Email siswa unik & wajib (anonim RFID-only tidak dipakai di v1)

## Technical Notes
- `User::assignRfid($uid, $label)` — creates card, normalisasi ke huruf besar
- `User::revokeRfid($cardId)` — soft revoke
- `User::topUp($amountCents, $description)` — atomic increment + audit log
- `User::activeRfidCards()` — kartu yang belum dicabut
- Saldo disimpan sebagai `balance_cents` BIGINT; input form dalam Rupiah lalu `× 100`
- Top-up memakai `DB::transaction` untuk atomisitas
- Password cukup di-*assign* mentah karena cast `hashed` pada model sudah meng-hash otomatis

## Deviations from ticket (alasannya)
- **Top-up tidak membuat row `transactions`.** Kolom `transactions.console_id` bersifat
  NOT NULL dengan FK ke console, sedangkan top-up dilakukan admin lewat panel web tanpa
  console. SPEC juga tidak memodelkan top-up sebagai transaksi, melainkan sebagai
  pembaruan `balance_cents` + jejak audit. Pendekatan ini menghindari schema yang
  tidak ada di SPEC.
- **`label` pada `rfid_cards`** ditambahkan sebagai kolom tambahan (tidak ada di SPEC)
  supaya admin bisa menandai "Kartu utama" vs "Kartu cadangan".
- **Import CSV tidak dikerjakan** (sudah ditandai opsional).

## Bug yang ditemukan & diperbaiki (di luar scope ticket)
| Lokasi | Masalah |
|---|---|
| `config/logging.php` | Channel `audit` tidak pernah didefinisikan → `Log::channel('audit')` melempar exception. impacting Ticket 03 & 04 |
| `audit_logs` migration | `id` = `bigIncrements` padahal SPEC = UUID PK dan model memakai `HasUuids` → "datatype mismatch" |
| `rfid_cards.rfid_uid` | `CHAR(14)` → `CHAR(8)` sesuai SPEC; `CHAR(14)` mem-pad nilai dengan spasi |
| `UserController` + views | Mengirim `consoles.id` (UUID) ke kolom `users.device_id` yang FK ke `consoles.device_id` |
| `resources/views/layouts/cashier.blade.php` | Dipindah ke `components/layouts/` → `/cashier/login` membalas 500 |
| `phpunit.xml` | `DB_CONNECTION`/`DB_DATABASE` dikomentari → test suite menghapus database dev tiap kali dijalankan |
| `routes/web.php` | Route `login` causing redirect ganda; `x-data` menunjuk fungsi Alpine yang tidak ada |

## Dependencies (terpenuhi)
- Ticket 01 (User, RfidCard models + migrations)
- Ticket 02 (admin shell)
- Ticket 03 (consoles for cashier assignment)
- Ticket 05 (Cashier Web UI for cashier-console linking)

## API Impact
- `/api/console/balance/{uid}` membaca `rfid_cards` → `user` → `balance_cents` (Ticket 08)

## Verifikasi
- `tests/Feature/UserControllerTest.php` — 35 test
- `tests/Feature/AdminUsersSmokeTest.php` — 4 test render halaman
- Total suite: **100 test lulus, 323 assertion** (sebelumnya 61 test)
- Dicek juga lewat HTTP langsung: 21 pemeriksaan render + 8 operasi tulis (create user, assign RFID, top-up, revoke, delete)