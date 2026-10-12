# Ticket 03: Console CRUD + Credential Rotation

## Objective
Build admin interface for console management: list, create, edit, delete, status toggle, and credential rotation (password + API key).

## Scope
- [ ] Controller: `Admin\ConsoleController` (resource actions)
- [ ] Routes: `admin.consoles.*` (index, create, store, show, edit, update, destroy)
- [ ] Views:
  - `admin/consoles/index.blade.php` — DataTable (Alpine.js): columns Device ID, Name, Location, Status, Firmware, Last Seen, Actions
  - `admin/consoles/create.blade.php` — Form: device_id (auto-gen from MAC pattern), name, location
  - `admin/consoles/edit.blade.php` — Pre-filled form + credential rotation section
- [ ] Credential rotation:
  - Button "Rotate Credentials" → POST `/admin/consoles/{id}/rotate-credentials`
  - Generates new `password_hash` (bcrypt) + `api_key` (64-char hex)
  - Shows new credentials **once** in modal (copy to clipboard)
  - Logs to `audit_logs` (action: `console.credentials_rotated`)
- [ ] Status badge: Online (green, last_seen < 2 min), Offline (gray), Maintenance (yellow)
- [ ] Soft delete? No — hard delete with confirmation modal
- [ ] Pagination: 15 per page, search by device_id/name

## Acceptance Criteria
- Admin can CRUD consoles
- Credential rotation invalidates old api_key immediately
- New api_key works for console registration/login
- Audit log entry created on rotate
- Console status updates via Realtime heartbeat (later ticket)

## Technical Notes
- `device_id` format validation: `^ESP-[0-9A-F]{12}$` (MAC-based)
- `Console::generateDeviceId()` helper
- API key: `bin2hex(random_bytes(32))`
- Password: `Str::random(16)` → bcrypt → store hash
- Use `console_credentials` table (1:1 with consoles)

## Dependencies
- Ticket 01 (Console model, migration)
- Ticket 02 (admin shell, middleware)

## API Impact
- Console registration endpoint (`/api/console/register`) will check `console_credentials` for valid api_key