# Ticket 12: Audit Logs + Admin Settings

## Objective
Complete audit trail for all admin actions and system events, plus admin settings page (password change, system config).

## Scope
- [ ] Audit Logging:
  - Automatic via Model Observers: `User`, `Console`, `MenuItem`, `Category`, `RfidCard`, `Transaction`
  - Manual in controllers: login, logout, credential rotate, balance top-up, RFID assign/revoke
  - `AuditLog` fields: `user_id`, `console_id`, `action`, `metadata` (JSON), `ip_address`
  - Actions taxonomy:
    - `user.created`, `user.updated`, `user.deleted`
    - `user.rfid_assigned`, `user.rfid_revoked`, `user.balance_topup`
    - `console.created`, `console.updated`, `console.deleted`, `console.credentials_rotated`
    - `menu.created`, `menu.updated`, `menu.deleted`, `menu.reordered`
    - `transaction.created`, `transaction.voided`, `transaction.completed`
    - `order.created`, `order.cancelled` (awaiting_rfid)
    - `admin.login`, `admin.logout`, `admin.password_changed`
    - `cashier.login`, `cashier.logout`
  - Middleware: auto-log IP on all admin routes
- [ ] Admin Audit Log View:
  - Route: `admin.audit-logs.index`
  - View: `admin/audit-logs/index.blade.php`
  - Filters: date range, action (select), user (search), console (search)
  - Table: Timestamp, Actor, Action, Target, Metadata (JSON pretty), IP
  - Export CSV (same pattern as Ticket 11)
  - Pagination: 50 per page
- [ ] Admin Settings Page:
  - Route: `admin.settings.index` (GET), `admin.settings.update` (PUT)
  - View: `admin/settings/index.blade.php`
  - Tabs:
    1. **Profile**: name, email, change password (current + new + confirm)
    2. **System**: timezone, currency format, receipt prefix, auto-void minutes (default 5), order expiry seconds (default 60)
    3. **Backup**: "Export Database" button (SQL dump via `spatie/laravel-db-snapshots` or custom)
  - Password change: validates current password, updates hash, logs `admin.password_changed`
  - System settings: stored in `config()` or `settings` table (new migration?) → use `spatie/laravel-settings` or simple `key_value` table

## Acceptance Criteria
- Every admin CRUD action creates audit log entry
- Audit log filter + search works
- Password change requires current password
- System settings persist and affect app behavior (e.g., auto-void minutes used in firmware, order expiry used in scheduler)
- Export CSV includes all columns

## Technical Notes
- Use `Spatie\Activitylog` package? Or custom Observer to avoid dependency.
- Custom Observer simpler: `AuditLogObserver` on each model
- `metadata` JSON: store `old` + `new` values for updates
- IP: `$request->ip()` in middleware
- Settings table migration (if not using package):
  ```php
  Schema::create('settings', function (Blueprint $t) {
      $t->string('key')->primary();
      $t->text('value')->nullable();
      $t->string('type')->default('string'); // string, int, bool, json
      $t->timestamps();
  });
  ```
- Default settings seeded in `DatabaseSeeder`

## Dependencies
- Ticket 01 (AuditLog model + migration)
- Ticket 02 (Admin shell)
- Ticket 03, 04, 05, 06, 08 (observers on those models)

## Testing
- Unit: Observer fires on model events
- Feature: Admin performs action → audit log entry exists
- Feature: Settings save + retrieve