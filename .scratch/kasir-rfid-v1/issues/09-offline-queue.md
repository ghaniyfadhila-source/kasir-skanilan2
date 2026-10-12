# Ticket 09: Offline Queue + Auto-void

## Objective
Implement robust offline transaction queue on ESP8266 (SPIFFS/LittleFS) with batch sync, retry logic, and auto-void for stale transactions.

## Scope
- [ ] ESP8266 Queue Module (`src/queue.cpp`):
  - Storage: LittleFS file `/queue/txns.jsonl` (JSON Lines)
  - Max size: 500 KB (configurable)
  - Record schema:
    ```json
    {
      "idempotency_key": "ESP-A1B2C3-1234567890-a1b2",
      "payload": {"items":[{"menu_item_id":"uuid","quantity":2}],"buyer_uid":"A1B2C3D4"},
      "created_at": 1728600000000,
      "retry_count": 0,
      "status": "pending"
    }
    ```
  - Functions: `enqueue()`, `flush()`, `remove()`, `markVoided()`, `getPendingCount()`
- [ ] Sync Logic:
  - On WiFi connect: wait 2s → `flush()`
  - `flush()`: read all pending → batch POST to `/api/console/sync` (or individual `/transaction`)
  - Server processes each with idempotency key
  - Response per item: `{idempotency_key, status: "success|duplicate|void|error", transaction_id?}`
  - Client: on success/duplicate → remove from queue; on error → increment retry_count
  - Max retries: 3 → then keep for manual intervention
- [ ] Auto-void:
  - Background task (every 60s): scan queue for `created_at < now - 5min`
  - For each stale: mark `status: "voided"`, remove from queue
  - On LCD: show "TXN VOIDED: {idempotency_key}" for 3s
  - Optional: notify server via `/transaction` with `status: "voided"` (idempotent)
- [ ] Queue Persistence:
  - Survives power loss (LittleFS wear leveling)
  - On boot: load queue, resume pending
- [ ] Queue Full Handling:
  - If > 90% capacity: show "QUEUE FULL" on LCD, reject new transactions with long buzzer
  - Admin can clear queue via Realtime command `queue.clear`

## Acceptance Criteria
- Power cycle during offline → queue intact on reboot
- 5 min auto-void triggers correctly
- Batch sync processes 50 transactions < 10s
- Retry logic respects max 3 attempts
- Queue full protection works

## Technical Notes
- LittleFS vs SPIFFS: LittleFS better for wear leveling, use `LittleFS` library
- Time source: NTP on WiFi connect, fallback to millis() drift (max 5 min tolerance)
- Idempotency key must be generated BEFORE enqueue (client-side)
- Server `/api/console/sync` endpoint accepts array of transactions
- Queue file format: 1 JSON per line → easy append/read partial

## Dependencies
- Ticket 06 (Firmware base + SPIFFS)
- Ticket 07 (Transaction API + idempotency)
- Ticket 08 (Realtime for queue.clear command)

## Testing
- Wokwi: simulate WiFi disconnect → queue 10 txns → reconnect → verify flush
- Wokwi: queue 1 txn → wait 6 min (mock time) → verify auto-void
- Physical: unplug router → tap RFID 5x → plug router → verify sync