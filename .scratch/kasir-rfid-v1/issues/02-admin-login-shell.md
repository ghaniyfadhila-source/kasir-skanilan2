# Ticket 02: Admin Login + Shell Dashboard

## Objective
Implement admin authentication (email/password) with rate limiting, role-based middleware, Blade layouts (auth + app shell), and a functional dashboard with stats.

## Scope
- [x] `Admin\Http\Controllers\Auth\LoginController` — show form, login, logout
- [x] Rate limiting: 5 attempts/minute per IP (ThrottleRequests middleware)
- [x] `AdminMiddleware` — verify `auth()->user()->isAdmin()` else 403
- [x] Routes: `/admin/login` (GET/POST), `/admin/logout` (POST), `/admin/dashboard` (GET)
- [x] Blade layouts:
  - `resources/views/components/layouts/auth.blade.php` — centered card, Tailwind CDN, Inter font
  - `resources/views/components/layouts/app.blade.php` — sidebar (collapsible mobile), topbar, Alpine.js `x-data="{sidebarOpen:false}"`
- [x] Views:
  - `auth/admin-login.blade.php` — email/password/remember, error bag, Alpine.js form validation
  - `admin/dashboard.blade.php` — 4 stat cards (users, consoles online, menu items, transactions today), quick action grid (placeholder links), recent activity placeholder
- [x] Dashboard controller logic: aggregate stats from DB
- [x] Tests: 8 passing (`AdminLoginTest` — login, role check, rate limit, logout, guest redirect)

## Acceptance Criteria
- Admin can login with `admin@kasir.local` / `password123`
- Cashier role redirected/denied from `/admin/*` (403)
- Rate limit triggers after 5 failed attempts → error message "Terlalu banyak percobaan login. Coba lagi dalam {seconds} detik."
- Sidebar responsive: hamburger on mobile (<768px), overlay close on click outside
- All tests pass: `php artisan test --filter=AdminLoginTest`

## Technical Notes
- Uses Laravel's built-in `RateLimiter` facade (no external package)
- Session driver: `database` (migration `create_sessions_table` + `create_cache_table` added in this ticket)
- CSRF protection via `@csrf` blade directive
- Alpine.js v3 via CDN: `https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js`
- Tailwind via CDN: `https://cdn.tailwindcss.com` (no build step required)

## Dependencies
- Ticket 01 (models, migrations, seeder)

## Testing Commands
```bash
php artisan test --filter=AdminLoginTest
php artisan serve --port=8000  # manual test at /admin/login
```