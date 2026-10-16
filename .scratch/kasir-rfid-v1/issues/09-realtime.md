# Ticket 09: Supabase Realtime Integration (3 Channels)

## Status
**IN PROGRESS** - infrastruktur selesai, tiga channel belum dibangun.

## Yang sudah selesai
- [x] Setup project Supabase di region `ap-southeast-1` (Singapore)
- [x] Tabel dibuat dari migration, bukan DDL manual
- [x] Row Level Security aktif di seluruh tabel
- [x] 6 tabel terdaftar di publication `supabase_realtime`
- [x] Storage bucket `firmware` dibuat, private
- [x] Perkakas: `supabase:configure`, `supabase:check`, `supabase:detect-region`
- [x] Perkakas: `cashier:smoke` untuk menguji alur kasir lewat HTTP

## Objective
Integrate Supabase Realtime (WebSocket) for live updates across 3 channels: console↔backend (bidirectional), cashier web UI, admin dashboard.

## Scope
- [x] Supabase Realtime configuration:
  - Enable Realtime on tables: `consoles`, `menu_items`, `transactions`,
    `transaction_items`, `rfid_cards`, `users`
  - Publication: `supabase_realtime` (default)
- [ ] **Channel 1: `console:{device_id}`** — **Bidirectional**
  - **Backend → ESP8266**: 
    - `awaiting_rfid` (order_id, total_cents, expires_at)
    - `order_cancelled` (order_id)
    - `menu.updated` (menu_item_id, action)
    - `config.changed` (key, value)
    - `ota.available` (version, url, sha256)
  - **ESP8266 → Backend**:
    - `rfid_tap` (rfid_uid, order_id)
    - `heartbeat` (ip, rssi, firmware, status)
    - `status` (online/offline/maintenance)
  - Auth: api_key as token (verify in backend)
- [ ] **Channel 2: `cashier:{device_id}`** — Backend → Cashier Web UI
  - `awaiting_rfid` (order_id, total_cents)
  - `completed` (transaction_id, receipt_no, new_balance_cents)
  - `void` (order_id, reason)
  - `error` (code, message)
- [ ] **Channel 3: `admin:dashboard`** — Backend → Admin Browsers (N)
  - `transaction.created` (transaction_id, receipt_no, total_cents, console_name, buyer_name)
  - `console.status` (device_id, status, firmware, rssi)
  - `stats.updated` (users, consoles_online, menu_items, transactions_today)
  - `menu.updated` (menu_item_id, action)
- [ ] Backend broadcasting:
  - Use Supabase PHP client or HTTP `POST /realtime/v1/broadcast` with service role key
  - Trigger on: order created, RFID tap processed, menu changed, console heartbeat, transaction completed
- [ ] Frontend subscriptions (Alpine.js + Supabase JS):
  - Cashier Web UI: subscribe `cashier:{device_id}` → auto-navigate pages
  - Admin Dashboard: subscribe `admin:dashboard` → live stats + activity feed
  - Console firmware: subscribe `console:{device_id}` (Phoenix Channels)

## Acceptance Criteria
- Menu price change in admin → all online consoles receive `menu.updated` within 2s
- Cashier clicks "BAYAR" → ESP8266 receives `awaiting_rfid` → LCD shows "TAP RFID | Rp X"
- Pembeli tap RFID → Cashier Web UI receives `completed` → auto-show success page
- Console heartbeat → admin dashboard "Online" badge updates in real-time
- New transaction → appears in admin dashboard "Recent Activity" without refresh
- Connection auto-reconnect on network drop (exponential backoff)
- 3 channels work simultaneously without interference

## Technical Notes
- Supabase Realtime uses Phoenix Channels protocol over WebSocket
- Backend publishes via Supabase REST: `POST /realtime/v1/broadcast` with service role key
- Channel naming:
  - `console:{device_id}` — 1 ESP8266 + backend publisher
  - `cashier:{device_id}` — 1 Cashier Web UI + backend publisher
  - `admin:dashboard` — N admin browsers + backend publisher
- Event payloads:
  ```json
  // awaiting_rfid (backend → console)
  {"type":"broadcast","event":"awaiting_rfid","payload":{"order_id":"uuid","total_cents":15000,"expires_at":"2026-10-14T10:01:00Z"}}
  
  // rfid_tap (console → backend)
  {"type":"broadcast","event":"rfid_tap","payload":{"rfid_uid":"A1B2C3D4","order_id":"uuid"}}
  
  // completed (backend → console + cashier)
  {"type":"broadcast","event":"completed","payload":{"transaction_id":"uuid","receipt_no":"TXN-20261014-001","new_balance_cents":85000}}
  
  // transaction.created (backend → admin)
  {"type":"broadcast","event":"transaction.created","payload":{"transaction_id":"uuid","receipt_no":"TXN-20261014-001","total_cents":15000,"console_name":"Kasir Utama","buyer_name":"Budi"}}
  ```
- Console firmware: Use `WebSocketsClient` library for Phoenix Channels
- Cashier Web UI: Use `@supabase/supabase-js` CDN in Blade

## Dependencies
- Ticket 01 (Supabase project setup + Realtime enabled)
- Ticket 03 (Console model, status field)
- Ticket 04 (MenuItem changes trigger broadcast)
- Ticket 05 (Cashier Web UI authentication + Realtime subscription)
- Ticket 08 (Transaction API triggers broadcasts)

## Frontend (Alpine.js + Supabase JS)
```javascript
// Cashier Web UI (cashier/menu.blade.php)
x-data="{
  init() {
    this.supabase = window.supabase.createClient('{{ env('SUPABASE_URL') }}', '{{ env('SUPABASE_ANON_KEY') }}');
    this.channel = this.supabase.channel('cashier:' + this.deviceId);
    this.channel.on('broadcast', {event: 'awaiting_rfid'}, e => this.gotoAwaiting(e.payload));
    this.channel.on('broadcast', {event: 'completed'}, e => this.gotoSuccess(e.payload));
    this.channel.on('broadcast', {event: 'void'}, e => this.gotoMenu(e.payload));
    this.channel.subscribe();
  }
}"

// Admin Dashboard (admin/dashboard.blade.php)
x-data="{
  init() {
    this.supabase = window.supabase.createClient('{{ env('SUPABASE_URL') }}', '{{ env('SUPABASE_ANON_KEY') }}');
    this.channel = this.supabase.channel('admin:dashboard');
    this.channel.on('broadcast', {event: 'transaction.created'}, e => this.addTransaction(e.payload));
    this.channel.on('broadcast', {event: 'console.status'}, e => this.updateConsoleStatus(e.payload));
    this.channel.subscribe();
  }
}"
```

## Testing
- Manual: edit menu in admin → verify console receives `menu.updated` (log on firmware)
- Manual: cashier order → verify ESP receives `awaiting_rfid` → LCD updates
- Manual: RFID tap → verify Cashier Web UI receives `completed` → navigates to success
- Manual: create transaction → verify admin dashboard updates live
- Load test: 50 concurrent admin tabs + 10 consoles + 10 cashier Web UIs