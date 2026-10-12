# Ticket 01: Bootstrap Laravel 11 + Supabase + Schema

## Objective
Initialize Laravel 11 project with PHP 8.3, configure Supabase (PostgreSQL) connection, create all 9 migrations, models with UUID traits, seeders, and Supabase RLS schema.sql.

## Scope
- [x] Laravel 11 project at repo root
- [x] PHP 8.3 requirement (local `php/` directory for dev)
- [x] 9 migrations: users, rfid_cards, consoles, console_credentials, categories, menu_items, transactions, audit_logs, password_resets
- [x] 9 Models with `HasUuids` trait + relationships
- [x] AdminUserSeeder: `admin@kasir.local` / `password123`
- [x] `database/supabase/schema.sql` with full DDL + RLS policies
- [x] README.md with setup instructions (local + Supabase)
- [x] `.env.example` with Supabase placeholders
- [x] GitHub repo created: https://github.com/ghaniyfadhila-source/kasir-skanilan2.git
- [x] Commit: `116283d` pushed to `main`

## Acceptance Criteria
- `php artisan migrate --seed` runs clean on SQLite (local) and PostgreSQL (Supabase)
- All models have UUID PK, proper casts, accessors (`isAdmin()`, `isCashier()`)
- RLS policies enforce: admin full access, cashier own console, public menu read
- `database/supabase/schema.sql` executable on fresh Supabase project

## Technical Notes
- UUID: use `Illuminate\Database\Eloquent\Concerns\HasUuids` (Laravel 11 built-in)
- Balance stored as `balance_cents` BIGINT (avoid float)
- Idempotency key on transactions: `CHAR(64)` UNIQUE
- Queue driver: `database` (migrations include `jobs` table)
- Session driver: `database` (migration created in Ticket 02)

## Dependencies
- None (foundation ticket)

## Testing
- `php artisan test` passes (2 default tests)
- Manual: `php artisan migrate:fresh --seed` → verify admin user exists