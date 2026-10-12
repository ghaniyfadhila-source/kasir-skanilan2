# Ticket 14: E2E Testing + Documentation

## Objective
End-to-end test coverage for critical flows, API documentation, and deployment guides.

## Scope
- [ ] E2E Tests (Laravel Dusk or Pest Browser):
  - Admin login → dashboard → create console → rotate credentials
  - Admin create category + menu item → verify appears in console menu API
  - Admin create student + assign RFID → top-up balance
  - Console (simulated) RFID tap → balance check → transaction → sync
  - Offline queue: disconnect network → 3 transactions → reconnect → verify sync
  - Auto-void: queue transaction → wait 5 min → verify voided
  - OTA: upload firmware → deploy → verify version update (mock)
- [ ] API Documentation:
  - OpenAPI/Swagger spec: `docs/api/openapi.yaml`
  - Generate from route annotations (use `darkaonline/l5-swagger`)
  - Endpoints: all `/api/console/*` + admin auth
  - Examples: request/response JSON
- [ ] Documentation:
  - `docs/hardware/assembly.md` — wiring diagram, pinout, component photos
  - `docs/hardware/firmware-flashing.md` — PlatformIO build + flash steps
  - `docs/deployment/supabase-setup.md` — project creation, RLS, Realtime, Storage
  - `docs/deployment/laravel-deploy.md` — server requirements, env, queue worker, scheduler
  - `docs/operations/backup-restore.md` — DB backup, code backup, firmware versioning
  - `docs/operations/troubleshooting.md` — common issues (WiFi, RFID, sync, OTA)
  - `CHANGELOG.md` — per version (keepachangelog format)
- [ ] GitHub Actions CI:
  - `.github/workflows/ci.yml` — PHP tests, Laravel Pint, Dusk (headless Chrome)
  - `.github/workflows/firmware.yml` — PlatformIO build + Wokwi simulation
  - `.github/workflows/deploy.yml` — staging deploy on merge to main

## Acceptance Criteria
- All E2E tests pass in CI
- OpenAPI spec valid (swagger.io validator)
- Documentation covers hardware + software deployment
- CI runs on every PR, blocks merge on failure
- Firmware build artifact uploaded as GitHub Release asset

## Technical Notes
- Dusk: requires ChromeDriver, use `laravel/dusk` + `browsershot` for screenshots
- Pest Browser: newer, faster alternative to Dusk
- Wokwi CI: `wokwi-cli` for headless simulation
- Supabase CLI for local development: `supabase start` (optional)
- Documentation: Markdown in `docs/`, render with GitHub Pages or VitePress later

## Dependencies
- All previous tickets (01-13)

## Deliverables Checklist
- [ ] `tests/Browser/` — Dusk test cases
- [ ] `docs/api/openapi.yaml` — generated
- [ ] `docs/hardware/` — assembly + flashing
- [ ] `docs/deployment/` — supabase + laravel
- [ ] `docs/operations/` — backup + troubleshooting
- [ ] `CHANGELOG.md` — v1.0.0 entries
- [ ] `.github/workflows/` — CI + firmware + deploy
- [ ] `README.md` updated with links to docs

## Final Verification
```bash
# Full test suite
php artisan test
php artisan dusk

# Firmware build
cd firmware && pio run -e esp8266

# API docs
php artisan l5-swagger:generate

# Deploy checklist
# 1. Supabase project created (prod)
# 2. RLS policies applied
# 3. Realtime enabled on tables
# 4. Storage bucket 'firmware' created
# 5. Laravel .env configured
# 6. Queue worker running (supervisor)
# 7. Scheduler cron: * * * * * php artisan schedule:run
# 8. Firmware v1.0.0 uploaded to Storage
# 9. Consoles registered + credentials distributed
```