# Ticket 13: Forgot Password (Admin Code)

## Objective
Implement "forgot password" flow where admin generates a 6-digit reset code for any user (no email sending).

## Scope
- [ ] Controller: `Admin\Auth\ForgotPasswordController`
- [ ] Routes:
  - `GET /admin/password/reset/{user}` — show generate code form
  - `POST /admin/password/reset/{user}` — generate 6-digit code
  - `GET /password/reset` — public reset form (enter code + new password)
  - `POST /password/reset` — validate code + update password
- [ ] Admin Flow:
  - On `admin/users/index`: button "Reset Password" per user
  - Modal: confirms user, button "Generate Code"
  - Code displayed **once** in modal (copy to clipboard)
  - Code stored in `password_resets` table: `user_id`, `code` (6 digits), `expires_at` (now + 1 hour), `used_at` (null)
  - Logs `audit_logs` (action: `admin.password_reset_generated`)
- [ ] Public Reset Flow:
  - Route accessible without auth: `/password/reset`
  - Form: email/username (optional), code (6 digits), new password, confirm
  - Validation: code exists, not expired, not used, matches user
  - On success: update `users.password_hash`, set `password_resets.used_at = NOW()`, log `audit_logs` (action: `user.password_reset`)
  - Redirect to `/admin/login` with success message
- [ ] Security:
  - Rate limit: 3 codes per user per hour
  - Code: `random_int(100000, 999999)` (numeric string)
  - Expire: 1 hour (configurable via settings)
  - One-time use only

## Acceptance Criteria
- Admin can generate reset code for any user
- Code works once within 1 hour
- Expired/invalid/used codes rejected with clear message
- No email dependency (fully offline-capable)
- Audit trail for both generation and usage

## Technical Notes
- `PasswordReset::generateForUser($user)` → returns code string
- `PasswordReset::validateAndConsume($code, $user)` → bool
- Cleanup job: `php artisan password-resets:prune` (daily scheduler) → delete used/expired > 24h
- Public reset route uses `guest` middleware (or no auth middleware)

## Dependencies
- Ticket 01 (PasswordReset model + migration)
- Ticket 02 (Admin shell + auth)
- Ticket 12 (Audit logging)

## Testing
- Admin generates code → displayed correctly
- Public form with valid code → password updated
- Same code second time → rejected
- Expired code (mock time) → rejected
- Rate limit: 4th generation within hour → 429