# Ticket 10: Offline Queue + Auto-void (Hardware RFID Tap Only)

## Objective
Implement robust offline RFID tap queue on ESP8266 (LittleFS) with batch sync, retry logic, and auto-void for stale taps. Cashier Web UI handles order creation (always online).

## Scope
- [ ] ESP8266 Queue Module (`src/queue.cpp`):
  - Storage: LittleFS file `/queue/rfid_taps.jsonl` (JSON Lines)
  - Max size: 500 KB (configurable)
  - Record schema:
    ```json
    {
      "idempotency_key": "ESP-A1B2C3-1234567890-a1b2",
      "payload": {"rfid_uid": "A1B2C3D4", "order_id": "uuid"},
      "created_at": 1728600000000,
      "retry_count": 0,
      "status": "pending"
    }
    ```
  - Functions: `enqueueRfidTap()`, `flush()`, `remove()`, `markVoided()`, `getPendingCount()`
- [ ] Sync Logic:
  - On WiFi connect: wait 2s → `flush()`
  - `flush()`: read all pending → batch POST to `/api/console/sync`
  - Server processes each with idempotency key (matches order_id + rfid_uid)
  - Response per item: `{idempotency_key, status: "success|duplicate|void|error|insufficient_balance", transaction_id?, new_balance_cents?}`
  - Client: on success/duplicate/void/insufficient_balance → remove from queue, show result on LCD; on error → increment retry_count
  - Max retries: 3 → then keep for manual intervention
- [ ] Auto-void:
  - Background task (every 60s): scan queue for `created_at < now - 5min`
  - For each stale: mark `status: "voided"`, remove from queue
  - On LCD: show "TXN VOIDED: {idempotency_key}" for 3s
  - Notify server via `/api/console/sync` with `status: "voided"` (idempotent)
- [ ] Queue Persistence:
  - Survives power loss (LittleFS wear leveling)
  - On boot: load queue, resume pending
- [ ] Queue Full Handling:
  - If > 90% capacity: show "QUEUE FULL" on LCD, reject new RFID taps with long buzzer
  - Admin can clear queue via Realtime command `queue.clear` (channel `console:{device_id}`)
- [ ] LCD States During Offline:
  - "OFFLINE MODE" (no WiFi)
  - "TAP RFID | Rp X" (awaiting RFID, order from cashier)
  - "OFFLINE - QUEUED" (RFID tapped, queued)
  - "SYNCING..." (flushing queue)
  - "TXN VOIDED: ..." (auto-void)

## Acceptance Criteria
- Power cycle during offline → queue intact on reboot
- 5 min auto-void triggers correctly for RFID taps
- Batch sync processes 50 RFID taps < 10s
- Retry logic respects max 3 attempts
- Queue full protection works
- LCD shows correct state for each offline scenario

## Technical Notes
- LittleFS vs SPIFFS: LittleFS better for wear leveling, use `LittleFS` library
- Time source: NTP on WiFi connect, fallback to millis() drift (max 5 min tolerance)
- Idempotency key must be generated BEFORE enqueue (client-side: `{device_id}-{timestamp}-{random4}`)
- Server `/api/console/sync` endpoint accepts array of RFID tap records
- Queue file format: 1 JSON per line → easy append/read partial
- Order expiration (60s) handled by backend scheduler, not hardware
- Hardware only queues RFID taps, not full transactions

## Dependencies
- Ticket 07 (Firmware base + LittleFS)
- Ticket 08 (Transaction API: rfid-tap + sync endpoints)
- Ticket 09 (Realtime for queue.clear command)

## Testing
- Wokwi: simulate WiFi disconnect → cashier order → tap RFID 5x → queue 5 taps → reconnect → verify flush
- Wokwi: queue 1 RFID tap → wait 6 min (mock time) → verify auto-void
- Physical: unplug router → cashier order → tap RFID 5x → plug router → verify sync + LCD shows success
- Edge: tap RFID for expired order → hardware receives `void` from backend on sync → LCD shows "DIBATALKAN"