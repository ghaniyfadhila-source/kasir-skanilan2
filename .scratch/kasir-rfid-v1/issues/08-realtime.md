# Ticket 08: Supabase Realtime Integration

## Objective
Integrate Supabase Realtime (WebSocket) for live updates: console ↔ backend, admin dashboard broadcasts.

## Scope
- [ ] Backend: Laravel Echo Server alternative → use Supabase Realtime directly via REST + WebSocket
- [ ] Supabase Realtime configuration:
  - Enable Realtime on tables: `consoles`, `menu_items`, `transactions`
  - Publication: `supabase_realtime` (default)
- [ ] Backend broadcasting:
  - On menu change (create/update/delete) → broadcast to `console:{device_id}` for all online consoles
  - On console status change (heartbeat) → broadcast to `admin:dashboard`
  - On new transaction → broadcast to `admin:dashboard` + `console:{device_id}` (receipt confirmation)
- [ ] Console firmware (Ticket 06) subscribes to `console:{device_id}`
- [ ] Admin dashboard (Blade + Alpine.js) subscribes to `admin:dashboard`
- [ ] Channel authorization:
  - Console channels: public (device has api_key) — or use Supabase RLS on Realtime
  - Admin channels: require authenticated admin user (JWT from session)

## Acceptance Criteria
- Menu price change in admin → all online consoles receive update within 2s
- Console heartbeat → admin dashboard shows "Online" badge in real-time
- New transaction → appears in admin dashboard "Recent Activity" without refresh
- Connection auto-reconnect on network drop

## Technical Notes
- Supabase Realtime uses Phoenix Channels protocol
- Laravel side: use `beyondcode/laravel-websockets` alternative? No — direct Supabase HTTP + JS client
- Backend publishes via Supabase REST: `POST /realtime/v1/broadcast` with service role key
- Or: use `supabase/supabase-php` client library
- Channel naming:
  - Console: `console:{device_id}` (1 publisher: backend, 1 subscriber: ESP8266)
  - Admin: `admin:dashboard` (1 publisher: backend, N subscribers: admin browsers)
  - Status: `console:status` (publisher: ESP8266 heartbeat, subscriber: backend + admin)
- Event payloads:
  ```json
  // menu.updated
  {"type":"broadcast","event":"menu.updated","payload":{"menu_item_id":"uuid","action":"price_change"}}
  // console.heartbeat
  {"type":"broadcast","event":"console.heartbeat","payload":{"device_id":"ESP-...","status":"online","firmware":"1.0.0"}}
  // transaction.created
  {"type":"broadcast","event":"transaction.created","payload":{"transaction_id":"uuid","total_cents":15000}}
  ```

## Dependencies
- Ticket 01 (Supabase project setup)
- Ticket 03 (Console model, status field)
- Ticket 04 (MenuItem changes trigger broadcast)
- Ticket 07 (Transaction creation triggers broadcast)

## Frontend (Alpine.js)
```javascript
// In admin/dashboard.blade.php
x-data="{
  init() {
    const channel = window.supabase.channel('admin:dashboard')
    channel.on('broadcast', {event: 'transaction.created'}, e => {
      this.transactions.unshift(e.payload)
    })
    channel.subscribe()
  }
}"
```

## Testing
- Manual: edit menu in admin → verify console receives (log on firmware)
- Manual: create transaction → verify admin dashboard updates live
- Load test: 50 concurrent admin tabs + 10 consoles