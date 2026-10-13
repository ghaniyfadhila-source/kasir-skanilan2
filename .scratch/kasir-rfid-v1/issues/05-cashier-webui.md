# Ticket 05: Cashier Web UI (Order + Awaiting RFID + Realtime)

## Objective
Build cashier-facing web interface (HP/Tablet) for creating orders and waiting for RFID tap. Real-time sync with console hardware via Supabase Realtime.

## Scope
- [ ] Controller: `App\Http\Controllers\Cashier\OrderController`
- [ ] Routes: `cashier.orders.*` (login, menu, store, status, cancel)
- [ ] Middleware: `cashier` (verify device_id + token, link to console)
- [ ] Views (Blade + Alpine.js):
  - `cashier/login.blade.php` — Login: device_id (select dari consoles aktif) + password
  - `cashier/menu.blade.php` — Menu grid by category, add to cart, cart sidebar, "BAYAR" button
  - `cashier/awaiting.blade.php` — Fullscreen "Menunggu Tap RFID" + realtime status, "BATAL" button
  - `cashier/success.blade.php` — Receipt detail + "Transaksi Baru" button
- [ ] Realtime Integration:
  - Subscribe channel `cashier:{device_id}` via Supabase JS client
  - Listen: `awaiting_rfid` → redirect to awaiting page
  - Listen: `completed` → redirect to success page
  - Listen: `void` / `error` → show error, back to menu
- [ ] Cart State: LocalStorage persist (survive refresh)
- [ ] Idempotency: Generate `{device_id}-{timestamp}-{random4}` per order

## Acceptance Criteria
- Cashier login dengan device_id + password → dapat Sanctum token
- Menu load dari `/api/cashier/menu` (category + items, is_active only)
- Klik "BAYAR" → POST `/api/cashier/order` → status=awaiting_rfid
- Realtime push dari backend → auto-navigate ke awaiting page
- Awaiting page: countdown 60s, "BATAL" button → POST `/api/cashier/order/{id}/cancel`
- RFID tap di hardware → realtime push `completed` → auto-navigate ke success
- Success page: tampil receipt_no, items, total, new_balance
- Offline: cart tersimpan LocalStorage, sync saat online

## Technical Notes
- `OrderController@store`: Create Transaction (status=awaiting_rfid, items JSON, total_cents, idempotency_key)
- `OrderController@status`: Return current transaction status
- `OrderController@cancel`: Update status=void, broadcast `order_cancelled` ke console
- Supabase Realtime: `supabase.channel('cashier:' + device_id).on('broadcast', ...)`
- Alpine.js: `x-data="cashierApp()"` dengan cart, orderId, realtime subscription

## Dependencies
- Ticket 01 (models, migrations)
- Ticket 03 (consoles, console_credentials)
- Ticket 04 (menu categories + items)
- Supabase Realtime enabled

## API Impact
- `POST /api/cashier/login` — returns `{token, device_id, console_name}`
- `GET /api/cashier/menu` — grouped by category
- `POST /api/cashier/order` — create awaiting_rfid transaction
- `GET /api/cashier/order/{id}/status` — poll fallback
- `POST /api/cashier/order/{id}/cancel` — void order