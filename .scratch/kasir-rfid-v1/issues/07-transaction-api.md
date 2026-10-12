# Ticket 07: Transaction API + Idempotency

## Objective
Build REST API endpoints for console transactions: balance check, transaction submission with idempotency, and atomic balance updates.

## Scope
- [ ] Controller: `Api\Console\TransactionController`
- [ ] Routes (prefix `api/console`, middleware `console.auth`):
  - `GET /balance/{uid}` — returns `{user_name, balance_cents, photo_url}`
  - `POST /transaction` — submit transaction
  - `POST /sync` — pull pending server-side queue (if any) + push status
- [ ] Middleware: `ConsoleAuth` — validates `X-Device-ID` + `X-API-Key` headers against `console_credentials`
- [ ] Balance Check:
  - Input: RFID UID (8-char hex)
  - Query: `RfidCard::where('uid', $uid)->where('is_active', true)->with('user')->firstOrFail()`
  - Response: `{ success: true, data: { user_name, balance_cents, photo_url } }`
- [ ] Transaction Submit:
  - Input: `{ idempotency_key, items: [{menu_item_id, quantity}], buyer_uid }`
  - Validation:
    - `idempotency_key` unique (DB constraint)
    - `buyer_uid` → valid active RFID → user with sufficient balance
    - Each `menu_item_id` → active, stock sufficient
    - Console matches authenticated device
  - Atomic DB transaction:
    1. Lock buyer user row (`SELECT ... FOR UPDATE`)
    2. Verify balance >= total
    3. Decrement buyer `balance_cents`
    4. Increment cashier `balance_cents` (console's linked cashier user)
    5. Create `Transaction` record (status `completed`, `synced_at = NOW()`)
    6. Create `TransactionItem` records (snapshot price)
    7. Decrement `MenuItem.stock` (if > -1)
    8. Create `AuditLog` (action: `transaction.created`)
  - Response: `{ success: true, data: { transaction_id, receipt_no, new_balance_cents } }`
- [ ] Idempotency:
  - Key format: `{device_id}-{timestamp_ms}-{random4}`
  - Server: UNIQUE index on `transactions.idempotency_key`
  - On duplicate: return existing transaction (200 OK with `duplicate: true`)
- [ ] Sync Endpoint:
  - For future: server-initiated voids, config changes
  - Returns: `{ pending_voids: [...], config_version: N }`

## Acceptance Criteria
- Concurrent requests with same idempotency_key → only 1 transaction created
- Insufficient balance → 422 with message "Saldo tidak mencukupi"
- Invalid RFID → 404 "Kartu tidak valid atau tidak aktif"
- Stock check prevents overselling
- All amounts in cents (integer)
- Audit log entry per transaction

## Technical Notes
- Use `DB::transaction()` with isolation level `serializable` or row locks
- `Transaction::generateReceiptNo()` → format: `TXN-{YYYYMMDD}-{sequence}`
- Cashier user: `Console->cashierUser` (via `device_id` on users table)
- Menu item price snapshot: store `unit_price_cents` at transaction time
- Stock decrement only if `stock > -1` (unlimited = -1)

## Dependencies
- Ticket 01 (Transaction, TransactionItem models + migrations)
- Ticket 03 (Console, ConsoleCredential for auth)
- Ticket 04 (MenuItem for price/stock)
- Ticket 05 (User, RfidCard for balance)

## Testing
```bash
# Unit
php artisan test --filter=TransactionTest

# Feature
# POST /api/console/transaction with valid idempotency_key → 201
# POST same key → 200 duplicate
# Insufficient balance → 422
```