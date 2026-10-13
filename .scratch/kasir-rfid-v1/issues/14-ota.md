# Ticket 14: OTA Firmware Update

## Objective
Enable Over-The-Air firmware updates for ESP8266 consoles via Supabase Storage + Realtime trigger.

## Scope
- [ ] Backend:
  - Admin upload firmware binary (.bin) via `admin/consoles` page (new tab "Firmware")
  - Store in Supabase Storage bucket `firmware` (private, signed URLs)
  - Metadata in `consoles` table: `firmware_version`, `firmware_url`, `firmware_sha256`, `firmware_released_at`
  - Button "Deploy to Console(s)" → selects target consoles → broadcasts `ota.available` event
- [ ] Supabase Storage:
  - Bucket: `firmware` (private)
  - Path: `{version}/firmware.bin` (e.g., `1.2.0/firmware.bin`)
  - Signed URL expiry: 1 hour (enough for ESP8266 download)
- [ ] Realtime Event:
  - Channel: `console:{device_id}`
  - Event: `ota.available`
  - Payload:
    ```json
    {
      "version": "1.2.0",
      "url": "https://{project}.supabase.co/storage/v1/object/sign/firmware/1.2.0/firmware.bin?token=...",
      "sha256": "a1b2c3d4...",
      "size": 327680
    }
    ```
- [ ] ESP8266 Firmware (Ticket 07 integration):
  - On `ota.available`: verify current version < new version
  - Download to OTA partition (HTTP GET with Range headers for resume)
  - Verify SHA256 matches
  - Write boot flag → reboot
  - On boot: if OTA partition valid → swap → mark success
  - Rollback on fail: watchdog timeout → revert to previous partition
- [ ] Admin UI:
  - `admin/firmware/index.blade.php` — list uploaded versions, status (deployed/pending), deploy buttons
  - Upload form: version (semver), .bin file, changelog (markdown)
  - Progress: per-console status (pending/downloading/verified/rebooted/failed)

## Acceptance Criteria
- Admin can upload .bin → stored in Supabase Storage
- Deploy triggers Realtime event to selected consoles
- ESP8266 downloads, verifies, installs, reboots
- Rollback works on corrupt firmware
- Version tracking in `consoles.firmware_version`

## Technical Notes
- ESP8266 OTA: use `ArduinoOTA` library or custom HTTP + `Update` class
- Partition scheme: factory + ota_0 + ota_1 (default PlatformIO)
- SHA256: `mbedtls_sha256` on ESP8266, `hash('sha256', $file)` on PHP
- Signed URL generation: Supabase PHP client `createSignedUrl()`
- Firmware naming: `firmware-v{version}.bin` → stored as `{version}/firmware.bin`
- Max firmware size: ~500KB (ESP8266 OTA partition limit)

## Dependencies
- Ticket 03 (Console CRUD + firmware_version field)
- Ticket 07 (Firmware OTA module)
- Ticket 09 (Realtime channel for ota.available)
- Supabase Storage bucket created manually or via migration

## Testing
- CI: compile firmware → upload artifact → test OTA on Wokwi (simulated)
- Physical: deploy v1.0.0 → OTA to v1.0.1 → verify version on LCD
- Failure case: corrupt binary → verify rollback