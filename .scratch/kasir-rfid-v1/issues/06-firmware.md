# Ticket 06: ESP8266 Firmware (PlatformIO)

## Objective
Develop ESP8266 firmware in PlatformIO (Arduino framework) implementing RFID read, LCD display, offline queue, Realtime subscription, and OTA update.

## Scope
- [ ] PlatformIO project at `firmware/` (or separate repo? → same repo under `firmware/`)
- [ ] `platformio.ini` with esp8266, nodemcuv2, SPIFFS partition
- [ ] Libraries: ArduinoJson, PubSubClient (MQTT not used — use HTTP + Supabase Realtime via WebSocket library), ArduinoHttpClient
- [ ] Modules:
  - `src/main.cpp` — setup(), loop(), state machine
  - `src/rfid.cpp` — MFRC522 wrapper, non-blocking poll
  - `src/lcd.cpp` — I2C LCD 1602/2004 driver (PCF8574), double-buffer
  - `src/buzzer.cpp` — tone patterns: success (2 beeps), error (long), offline (chirp)
  - `src/led.cpp` — status LEDs (green/red)
  - `src/network.cpp` — WiFiManager (AP fallback), HTTP client, Realtime WebSocket
  - `src/queue.cpp` — SPIFFS JSON Lines queue (enqueue, flush, auto-void >5min)
  - `src/ota.cpp` — OTA download + verify + reboot
  - `src/config.cpp` — device_id, api_key, server URLs (stored in SPIFFS)
- [ ] State Machine:
  ```
  BOOT → WIFI_CONNECT → REGISTER_DEVICE → MAIN_LOOP
                    ↓ (fail x3)
              AP_CONFIG_MODE (captive portal for WiFi creds)
  ```
- [ ] Main Loop (100ms):
  1. RFID poll (if card → read UID → GET /balance → display)
  2. LCD refresh (dirty flag)
  3. Buzzer/LED timers
  4. Network: process HTTP queue, handle Realtime messages
  5. Watchdog feed
- [ ] Offline Queue (SPIFFS):
  - File: `/queue/transactions.jsonl`
  - Max 500 KB, rotate if full
  - Record: `{idempotency_key, payload, created_at, retry_count}`
  - Flush on WiFi reconnect: batch POST to `/api/console/transaction`
  - Auto-void: records >5 min → mark voided, remove from queue, show "Void" on LCD
- [ ] Realtime Subscription:
  - Connect to Supabase Realtime WSS: `wss://{project}.supabase.co/realtime/v1/websocket`
  - Join channel `console:{device_id}` (JWT: api_key as token?)
  - Handle events: `menu.updated`, `config.changed`, `ota.available`
- [ ] OTA:
  - On `ota.available` event: download from signed URL (HTTPS)
  - Verify SHA256 (provided in event)
  - Write to OTA partition → set boot flag → reboot

## Acceptance Criteria
- Firmware compiles: `pio run -e esp8266`
- Device registers with backend (gets api_key)
- RFID read → displays balance/name on LCD
- Offline queue persists across reboot
- Auto-void works (simulate 5 min)
- OTA updates firmware successfully
- Memory usage < 80% RAM, < 70% Flash

## Technical Notes
- Supabase Realtime protocol: Phoenix Channels over WebSocket
  - Join: `{"topic":"console:{device_id}","event":"phx_join","payload":{},"ref":1}`
  - Auth: `"payload":{"token":"{api_key}"}` (verify backend accepts this)
- Alternative: Poll REST every 30s for menu/config if Realtime too complex for ESP8266
- SPIFFS: `LittleFS` preferred over deprecated SPIFFS
- WiFi credentials: WiFiManager captive portal (fallback AP mode)
- Device ID: `ESP-` + MAC (last 6 bytes uppercase) → `ESP-A1B2C3D4E5F6`

## Dependencies
- Ticket 01 (database schema for console registration)
- Ticket 03 (console registration API endpoint)
- Ticket 07 (transaction API)
- Ticket 08 (Realtime channel)

## Hardware Test
- Wokwi simulation CI (GitHub Actions)
- Physical test on NodeMCU v3 + RC522 + LCD 1602

## Build Commands
```bash
cd firmware
pio run -e esp8266          # build
pio run -e esp8266 -t upload # flash via USB
pio device monitor           # serial monitor
```