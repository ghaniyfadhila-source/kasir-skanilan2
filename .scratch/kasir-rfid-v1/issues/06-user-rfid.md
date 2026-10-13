# Ticket 06: User Management + RFID Assign

## Objective
Admin interface for managing users (students, cashiers), RFID card assignment, and balance top-up.

## Scope
- [ ] Controller: `Admin\UserController` (resource)
- [ ] Routes: `admin.users.*` (index, create, store, show, edit, update, destroy)
- [ ] Views:
  - `admin/users/index.blade.php` — Table: Name, Email, Role, Balance, RFID UID, Status, Actions
  - `admin/users/create.blade.php` — Form: name, email, role (student/cashier), password (optional for cashier), initial balance
  - `admin/users/edit.blade.php` — Pre-filled + RFID management section + Balance top-up form
  - `admin/users/show.blade.php` — Detail + transaction history (paginated)
- [ ] RFID Management:
  - Section "Kartu RFID" — list assigned cards (UID, status, issued_at)
  - Button "Tambah Kartu" → modal: scan UID (manual input) → creates `rfid_cards` record linked to user
  - Button "Revoke" → sets `revoked_at`, `is_active=false`
  - UID format: 8-char uppercase hex (validated)
- [ ] Balance Top-up:
  - Form: amount (Rupiah), description → creates `Transaction` type `topup` (new status? or reuse with cashier_id=null)
  - Shows updated balance immediately (Alpine.js reactive)
- [ ] Cashier creation:
  - Role=Cashier requires `device_id` select (from unassigned consoles)
  - Auto-generates console password + shows once
- [ ] Student import: CSV upload (name, email, initial_balance) — optional bonus

## Acceptance Criteria
- RFID UID uniqueness enforced (DB + form validation)
- Revoked cards cannot be used for transactions
- Balance top-up creates audit trail (`audit_logs`: action `user.balance_topup`)
- Cashier linked to exactly 1 console (1:1 via `users.device_id` → `consoles.device_id`)
- Student email optional (can be null for anonymous RFID-only)

## Technical Notes
- `User::assignRfid($uid)` — creates card, returns instance
- `User::revokeRfid($uid)` — soft revoke
- `User::topUp($amountCents, $description, $adminId)` — atomic balance update + transaction record
- Balance stored as `balance_cents` BIGINT
- Use DB transaction for top-up atomicity

## Dependencies
- Ticket 01 (User, RfidCard models + migrations)
- Ticket 02 (admin shell)
- Ticket 03 (consoles for cashier assignment)
- Ticket 05 (Cashier Web UI for cashier-console linking)

## API Impact
- `/api/console/balance/{uid}` reads `rfid_cards` → `user` → `balance_cents`