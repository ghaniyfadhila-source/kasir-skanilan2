# Ticket 08: Transaction API + Idempotency (Order + RFID Tap)

## Objective
Build REST API endpoints for cashier orders and hardware RFID taps with atomic balance updates and idempotency.

## Scope
- [ ] Controllers:
  - `Api\Cashier\OrderController` — Cashier Web UI endpoints
  - `Api\Console\TransactionController` — Hardware endpoints
- [ ] Middleware:
  - `CashierAuth` — validates `Authorization: Bearer {token}` (Sanctum), links to console via device_id
  - `ConsoleAuth` — validates `X-Device-ID` + `X-API-Key` headers against `console_credentials`
- [ ] Routes:
  - **Cashier** (prefix `api/cashier`, middleware `cashier.auth`):
    - `POST /login` — `{device_id, password}` → returns `{token, device_id, console_name}`
    - `GET /menu` — active menu grouped by category
    - `POST /order` — create awaiting_rfid order `{items[], idempotency_key}`
    - `GET /order/{id}/status` — poll fallback
    - `POST /order/{id}/cancel` — void awaiting_rfid order
  - **Console** (prefix `api/console`, middleware `console.auth`):
    - `POST /rfid-tap` — submit RFID tap `{rfid_uid, order_id, idempotency_key}`
    - `GET /balance/{uid}` — check RFID balance (optional)
    - `POST /sync` — pull pending + push status
    - `POST /heartbeat` — send status (ip, rssi, firmware)
- [ ] Cashier Order Create (`POST /api/cashier/order`):
  - Input: `{idempotency_key, items: [{menu_item_id, quantity}]}`
  - Validation:
    - `idempotency_key` unique (DB constraint)
    - Each `menu_item_id` → active, stock sufficient
    - Console matches authenticated cashier's device_id
  - Atomic DB transaction:
    1. Create `Transaction` (status=`awaiting_rfid`, `console_id`, `cashier_id`, `total_cents`, `items` JSON, `idempotency_key`)
    2. Create `TransactionItem` records (snapshot price, quantity)
    3. Reserve stock? (optional: decrement on RFID tap, not on order)
  - Response: `{success: true, data: {order_id, total_cents, expires_at}}`
  - Broadcast to `console:{device_id}`: `{type: "awaiting_rfid", order_id, total_cents, expires_at}`
- [ ] Hardware RFID Tap (`POST /api/console/rfid-tap`):
  - Input: `{rfid_uid, order_id, idempotency_key}`
  - Validation:
    - `rfid_uid` → valid active RFID card → buyer user
    - `order_id` → exists, status=`awaiting_rfid`, same console, not expired
    - `idempotency_key` unique (DB constraint)
  - Atomic DB transaction (serializable):
    1. Lock buyer user row (`SELECT ... FOR UPDATE`)
    2. Verify buyer `balance_cents >= total_cents`
    3. Decrement buyer `balance_cents`
    4. Update `Transaction`: status=`completed`, `buyer_id`, `synced_at=NOW()`
    5. Decrement `MenuItem.stock` (if > -1) per item
    6. Create `AuditLog` (action: `transaction.completed`)
  - Response: `{success: true, data: {transaction_id, receipt_no, new_balance_cents}}`
  - Broadcast to `console:{device_id}`: `{type: "success", receipt_no, new_balance_cents}`
  - Broadcast to `cashier:{device_id}`: `{type: "completed", receipt_no, new_balance_cents}`
- [ ] Cancel Order (`POST /api/cashier/order/{id}/cancel`):
  - Only if status=`awaiting_rfid`
  - Update status=`void`
  - Broadcast `order_cancelled` to `console:{device_id}`
- [ ] Idempotency:
  - Key format: `{device_id}-{timestamp_ms}-{random4}`
  - Server: UNIQUE index on `transactions.idempotency_key`
  - On duplicate: return existing transaction (200 OK with `duplicate: true`)

## Acceptance Criteria
- Concurrent orders with same idempotency_key → only 1 created
- RFID tap on completed/void order → 422 "Order sudah diproses"
- Insufficient balance → 422 "Saldo tidak mencukupi"
- Invalid RFID → 404 "Kartu tidak valid atau tidak aktif"
- Stock check prevents overselling (on RFID tap)
- Order expires after 60s → auto-void via scheduler
- All amounts in cents (integer)
- Audit log entry per transaction

## Technical Notes
- Use `DB::transaction()` with isolation level `serializable` or row locks
- `Transaction::generateReceiptNo()` → format: `TXN-{YYYYMMDD}-{sequence}`
- Cashier user: `Console->cashierUser` (via `device_id` on users table)
- Menu item price snapshot: store `unit_price_cents` at transaction time
- Stock decrement only if `stock > -1` (unlimited = -1)
- Scheduler: `php artisan schedule:run` every minute → void expired awaiting_rfid orders

## Dependencies
- Ticket 01 (Transaction, TransactionItem models + migrations with `awaiting_rfid` status)
- Ticket 03 (Console, ConsoleCredential for auth)
- Ticket 04 (MenuItem for price/stock)
- Ticket 05 (Cashier Web UI authentication)
- Ticket 06 (User, RfidCard for balance)

## Testing
```bash
# Unit
php artisan test --filter=TransactionTest

# Feature
# Cashier: POST /api/cashier/order → 201 awaiting_rfid
# Cashier: POST same idempotency_key → 200 duplicate
# Console: POST /api/console/rfid-tap with valid UID → 200 completed
# Console: POST same tap idempotency_key → 200 duplicate
# Insufficient balance → 422
# Expired order → 422
```