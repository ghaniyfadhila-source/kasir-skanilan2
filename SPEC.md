# Kasir Skanilan 2 — Technical Specification

**Version:** 1.0  
**Date:** 2026-10-11  
**Status:** Active Development

---

## 1. Project Overview

**Kasir Skanilan 2** adalah sistem POS (Point of Sale) berbasis RFID untuk kantin sekolah skala menengah. Sistem ini mendukung multi-console (beberapa terminal kasir), multi-cashier, dengan arsitektur offline-first pada hardware ESP8266.

### 1.1 Scope
- **Hardware:** ESP8266 single-chip, RC522 RFID, I2C LCD 1602/2004, buzzer, LED — powered via USB
- **Backend:** Laravel 11 + PHP 8.3, Sanctum auth, Supabase (PostgreSQL + Realtime) Singapore region
- **Frontend:** Blade + Alpine.js (no Inertia/Vue/React)
- **Auth:** 2 roles — admin (email/password) dan cashier (device_id + password dari admin)
- **Offline-first:** SPIFFS queue di ESP8266, auto-void pending >5 menit
- **Backup:** Manual via GitHub repo (no auto-backup ke Supabase)
- **Forgot password:** Admin generate reset code (bukan email)

---

## 2. Hardware Architecture

### 2.1 Bill of Materials
| Component | Spec | Qty | Notes |
|-----------|------|-----|-------|
| ESP8266 | ESP-12E / NodeMCU v3 | 1 per console | Single-chip, WiFi built-in |
| RFID RC522 | 13.56 MHz SPI | 1 per console | MIFARE Classic 1K support |
| I2C LCD | 1602 (16x2) atau 2004 (20x4) | 1 per console | PCF8574 backpack |
| Buzzer | Active 5V | 1 per console | Tone feedback |
| LED | 5mm + 220Ω resistor | 2 per console | Status (green/red) |
| Power | USB 5V 1A+ | 1 per console | Via USB-C / micro-USB |

### 2.2 Pin Mapping (ESP8266 / NodeMCU)
| Function | GPIO | NodeMCU Pin | Notes |
|----------|------|-------------|-------|
| RFID SDA (SS) | GPIO15 | D8 | |
| RFID SCK | GPIO14 | D5 | |
| RFID MOSI | GPIO13 | D7 | |
| RFID MISO | GPIO12 | D6 | |
| RFID RST | GPIO0 | D3 | |
| I2C SDA | GPIO4 | D2 | LCD |
| I2C SCL | GPIO5 | D1 | LCD |
| Buzzer | GPIO2 | D4 | Active HIGH |
| LED Green | GPIO16 | D0 | Status OK |
| LED Red | GPIO1 | TX | Status Error |

---

## 3. System Architecture

```
┌─────────────┐     WiFi/HTTPS      ┌──────────────┐     Realtime/WSS      ┌─────────────┐
│  ESP8266    │ ◄─────────────────► │  Laravel 11  │ ◄──────────────────►  │   Browser   │
│  (Console)  │   REST + Queue      │  (API + Web) │   Supabase Realtime   │  (Admin)    │
└─────────────┘                     └──────────────┘                       └─────────────┘
       │                                    │                                    │
       │ SPIFFS Queue                       │ PostgreSQL                          │
       │ (offline buffer)                   │ (Supabase)                          │
       ▼                                    ▼                                    ▼
┌─────────────────────────────────────────────────────────────────────────────────┐
│                              Supabase Project (ap-southeast-1)                   │
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐  ┌─────────────────────────┐ │
│  │ PostgreSQL  │  │  Realtime   │  │   Auth      │  │     Storage             │ │
│  │  (Primary)  │  │  (WebSocket)│  │  (optional) │  │   (firmware OTA)        │ │
│  └─────────────┘  └─────────────┘  └─────────────┘  └─────────────────────────┘ │
└─────────────────────────────────────────────────────────────────────────────────┘
```

### 3.2 Communication Protocols
- **Console → Backend:** HTTPS REST (JSON) + Supabase Realtime channels per console
- **Backend → Console:** Supabase Realtime broadcast (channel: `console:{device_id}`)
- **Admin ↔ Backend:** Standard HTTP + Blade SSR + Alpine.js reactivity
- **Firmware OTA:** HTTPS download dari Supabase Storage / GitHub Releases

---

## 4. Database Schema (9 Tables)

### 4.1 ER Diagram Summary
```
users ◄── rfid_cards (1:N)
users ◄── transactions (1:N) [buyer]
users ◄── transactions (1:N) [cashier]
consoles ◄── console_credentials (1:1)
consoles ◄── transactions (1:N)
categories ◄── menu_items (1:N)
menu_items ◄── transactions (1:N) [via transaction_items]
```

### 4.2 Table Definitions

#### users
| Column | Type | Constraints |
|--------|------|-------------|
| id | UUID | PK, default gen_random_uuid() |
| name | VARCHAR(100) | NOT NULL |
| email | VARCHAR(150) | UNIQUE, NOT NULL (admin only) |
| role | VARCHAR(20) | NOT NULL, CHECK IN ('admin','cashier','student') |
| balance_cents | BIGINT | DEFAULT 0, NOT NULL |
| password_hash | VARCHAR(255) | NOT NULL |
| device_id | VARCHAR(50) | UNIQUE, NULLABLE (cashier only) |
| is_active | BOOLEAN | DEFAULT TRUE |
| created_at | TIMESTAMPTZ | DEFAULT NOW() |
| updated_at | TIMESTAMPTZ | DEFAULT NOW() |

#### rfid_cards
| Column | Type | Constraints |
|--------|------|-------------|
| id | UUID | PK |
| uid | CHAR(8) | UNIQUE, NOT NULL (hex uppercase) |
| user_id | UUID | FK → users.id, NOT NULL |
| is_active | BOOLEAN | DEFAULT TRUE |
| issued_at | TIMESTAMPTZ | DEFAULT NOW() |
| revoked_at | TIMESTAMPTZ | NULLABLE |

#### consoles
| Column | Type | Constraints |
|--------|------|-------------|
| id | UUID | PK |
| device_id | VARCHAR(50) | UNIQUE, NOT NULL |
| name | VARCHAR(100) | NOT NULL |
| location | VARCHAR(100) | NULLABLE |
| status | VARCHAR(20) | DEFAULT 'offline', CHECK IN ('online','offline','maintenance') |
| firmware_version | VARCHAR(20) | NULLABLE |
| last_seen_at | TIMESTAMPTZ | NULLABLE |
| created_at | TIMESTAMPTZ | DEFAULT NOW() |

#### console_credentials
| Column | Type | Constraints |
|--------|------|-------------|
| console_id | UUID | PK, FK → consoles.id |
| password_hash | VARCHAR(255) | NOT NULL |
| api_key | CHAR(64) | UNIQUE, NOT NULL (hex) |
| created_at | TIMESTAMPTZ | DEFAULT NOW() |
| rotated_at | TIMESTAMPTZ | NULLABLE |

#### categories
| Column | Type | Constraints |
|--------|------|-------------|
| id | UUID | PK |
| name | VARCHAR(50) | UNIQUE, NOT NULL |
| sort_order | INT | DEFAULT 0 |
| is_active | BOOLEAN | DEFAULT TRUE |
| created_at | TIMESTAMPTZ | DEFAULT NOW() |

#### menu_items
| Column | Type | Constraints |
|--------|------|-------------|
| id | UUID | PK |
| category_id | UUID | FK → categories.id, NOT NULL |
| name | VARCHAR(100) | NOT NULL |
| price_cents | INT | NOT NULL, CHECK > 0 |
| image_url | TEXT | NULLABLE |
| is_active | BOOLEAN | DEFAULT TRUE |
| stock | INT | DEFAULT -1 (unlimited) |
| created_at | TIMESTAMPTZ | DEFAULT NOW() |
| updated_at | TIMESTAMPTZ | DEFAULT NOW() |

#### transactions
| Column | Type | Constraints |
|--------|------|-------------|
| id | UUID | PK |
| console_id | UUID | FK → consoles.id, NOT NULL |
| buyer_id | UUID | FK → users.id, NOT NULL |
| cashier_id | UUID | FK → users.id, NOT NULL |
| total_cents | INT | NOT NULL, CHECK > 0 |
| status | VARCHAR(20) | DEFAULT 'completed', CHECK IN ('completed','voided','pending_sync') |
| idempotency_key | VARCHAR(64) | UNIQUE, NOT NULL |
| created_at | TIMESTAMPTZ | DEFAULT NOW() |
| synced_at | TIMESTAMPTZ | NULLABLE |

#### transaction_items
| Column | Type | Constraints |
|--------|------|-------------|
| id | UUID | PK |
| transaction_id | UUID | FK → transactions.id, NOT NULL |
| menu_item_id | UUID | FK → menu_items.id, NOT NULL |
| quantity | INT | NOT NULL, CHECK > 0 |
| unit_price_cents | INT | NOT NULL |
| subtotal_cents | INT | GENERATED ALWAYS AS (quantity * unit_price_cents) STORED |

#### audit_logs
| Column | Type | Constraints |
|--------|------|-------------|
| id | UUID | PK |
| user_id | UUID | FK → users.id, NULLABLE |
| console_id | UUID | FK → consoles.id, NULLABLE |
| action | VARCHAR(50) | NOT NULL |
| metadata | JSONB | DEFAULT '{}' |
| ip_address | INET | NULLABLE |
| created_at | TIMESTAMPTZ | DEFAULT NOW() |

#### password_resets
| Column | Type | Constraints |
|--------|------|-------------|
| id | UUID | PK |
| user_id | UUID | FK → users.id, NOT NULL |
| code | CHAR(6) | NOT NULL (numeric) |
| expires_at | TIMESTAMPTZ | NOT NULL |
| used_at | TIMESTAMPTZ | NULLABLE |
| created_at | TIMESTAMPTZ | DEFAULT NOW() |

---

## 5. Row Level Security (RLS) Policies

```sql
-- Enable RLS on all tables
ALTER TABLE users ENABLE ROW LEVEL SECURITY;
ALTER TABLE rfid_cards ENABLE ROW LEVEL SECURITY;
ALTER TABLE consoles ENABLE ROW LEVEL SECURITY;
ALTER TABLE console_credentials ENABLE ROW LEVEL SECURITY;
ALTER TABLE categories ENABLE ROW LEVEL SECURITY;
ALTER TABLE menu_items ENABLE ROW LEVEL SECURITY;
ALTER TABLE transactions ENABLE ROW LEVEL SECURITY;
ALTER TABLE transaction_items ENABLE ROW LEVEL SECURITY;
ALTER TABLE audit_logs ENABLE ROW LEVEL SECURITY;
ALTER TABLE password_resets ENABLE ROW LEVEL SECURITY;

-- Admin full access
CREATE POLICY "admin_all" ON users FOR ALL TO authenticated USING (
  auth.jwt() ->> 'role' = 'admin'
);

-- Users read own data
CREATE POLICY "user_read_own" ON users FOR SELECT TO authenticated USING (
  auth.uid() = id
);

-- Cashier: read assigned console + own user
CREATE POLICY "cashier_console" ON consoles FOR SELECT TO authenticated USING (
  auth.jwt() ->> 'device_id' = device_id
);

-- Console credentials: only via service role
CREATE POLICY "service_only" ON console_credentials FOR ALL TO service_role USING (true);

-- Menu items: public read active, admin write
CREATE POLICY "menu_public_read" ON menu_items FOR SELECT USING (is_active = true);
CREATE POLICY "menu_admin_write" ON menu_items FOR ALL TO authenticated USING (
  auth.jwt() ->> 'role' = 'admin'
);

-- Transactions: cashier/console can insert, admin full
CREATE POLICY "txn_console_insert" ON transactions FOR INSERT TO authenticated WITH CHECK (
  auth.jwt() ->> 'device_id' = (SELECT device_id FROM consoles WHERE id = console_id)
);
CREATE POLICY "txn_admin_all" ON transactions FOR ALL TO authenticated USING (
  auth.jwt() ->> 'role' = 'admin'
);

-- Audit logs: admin only
CREATE POLICY "audit_admin" ON audit_logs FOR ALL TO authenticated USING (
  auth.jwt() ->> 'role' = 'admin'
);
```

---

## 6. API Endpoints

### 6.1 Auth
| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| POST | `/api/auth/login` | None | Admin login (email/password) |
| POST | `/api/auth/logout` | Sanctum | Logout |
| POST | `/api/auth/console-login` | None | Console login (device_id + password) |
| POST | `/api/auth/forgot-password` | Admin | Generate reset code for user |
| POST | `/api/auth/reset-password` | None | Reset password with code |

### 6.2 Console (device_id + api_key header)
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/console/menu` | Get active menu items by category |
| POST | `/api/console/transaction` | Submit transaction (idempotent) |
| GET | `/api/console/balance/{uid}` | Check RFID card balance |
| POST | `/api/console/sync` | Pull pending queue + push status |

### 6.3 Admin (web + API)
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/admin/dashboard` | Dashboard stats |
| CRUD | `/admin/consoles` | Console management |
| CRUD | `/admin/menu` | Categories + items |
| CRUD | `/admin/users` | Users + RFID cards |
| GET | `/admin/reports` | Daily/weekly/monthly reports |
| GET | `/admin/audit-logs` | System audit trail |

---

## 7. Supabase Realtime Channels

| Channel | Publisher | Subscribers | Payload |
|---------|-----------|-------------|---------|
| `console:{device_id}` | Backend | ESP8266 (1) | Menu updates, config changes, OTA trigger |
| `admin:dashboard` | Backend | Admin browsers (N) | Live stats, new transactions |
| `console:status` | ESP8266 | Backend + Admin | Heartbeat, online/offline |

### 7.1 Message Format
```json
{
  "event": "menu.updated",
  "timestamp": "2026-10-11T07:30:00Z",
  "data": { "menu_item_id": "uuid", "action": "price_change" }
}
```

---

## 8. ESP8266 Firmware Specification

### 8.1 State Machine
```
BOOT → WIFI_CONNECT → REGISTER_DEVICE → MAIN_LOOP
                    ↓ (fail)
              RETRY/AP_MODE
```

### 8.2 Main Loop (100ms tick)
1. **RFID Poll** — Non-blocking check for card present
2. **LCD Update** — Refresh display (double-buffered)
3. **Buzzer/LED** — Handle tone/blink timers
4. **Network** — Process HTTP queue, Realtime messages
5. **Watchdog** — Feed ESP watchdog

### 8.3 Offline Queue (SPIFFS)
- **Format:** JSON Lines per transaction
- **Max size:** 500 KB (~2000 transactions)
- **Fields:** idempotency_key, payload, created_at, retry_count
- **Flush:** On WiFi reconnect, batch POST to `/api/console/transaction`
- **Auto-void:** Transactions >5 min pending → mark voided locally, notify user

### 8.4 Device Registration Flow
1. Boot → Read MAC → Generate device_id = `ESP-{MAC}`
2. POST `/api/console/register` { device_id, hw_version }
3. Backend: if exists → return api_key; else → return 404 (wait for admin approval)
4. Store api_key in SPIFFS (encrypted with device unique key)

### 8.5 OTA Update
- Triggered via Realtime `console:{device_id}` event `ota.available`
- Download from Supabase Storage signed URL
- Verify SHA256 → Write to OTA partition → Reboot

---

## 9. Admin Web UI Specification

### 9.1 Pages
| Route | Component | Description |
|-------|-----------|-------------|
| `/admin/login` | Blade + Alpine | Email/password, rate limited |
| `/admin/dashboard` | Blade + Alpine | Stats cards, quick actions, recent activity |
| `/admin/consoles` | Blade + Alpine | Table CRUD, status badges, credential rotation |
| `/admin/menu` | Blade + Alpine | Nested categories + items, drag-sort, image upload |
| `/admin/users` | Blade + Alpine | Users table, RFID assign, balance top-up |
| `/admin/reports` | Blade + Alpine | Date picker, export CSV, charts (Chart.js) |
| `/admin/audit-logs` | Blade + Alpine | Filterable table, export |

### 9.2 Design System
- **Colors:** Blue primary (#2563EB), Green success (#16A34A), Red danger (#DC2626), Gray neutral
- **Typography:** Inter (Google Fonts), 400/500/600/700
- **Spacing:** 4px base unit (Tailwind default)
- **Components:** Card, Table, Modal, Toast, Badge, Button, Input, Select, DatePicker
- **Responsive:** Mobile-first, sidebar collapses <768px

### 9.3 Alpine.js Patterns
- `x-data` per component (sidebar, modal, table, form)
- `x-init` for Realtime subscription
- `$dispatch`/`$watch` for cross-component events
- `x-transition` for modals/dropdowns

---

## 10. Transaction Flow

### 10.1 Happy Path (Online)
```
1. Student tap RFID → ESP reads UID
2. ESP → Backend: GET /balance/{uid}
3. Backend → ESP: { balance, user_name, photo_url }
4. ESP displays balance + name on LCD
5. Cashier selects menu items on ESP keypad/touch
6. ESP calculates total, shows confirmation
7. Cashier confirms → ESP POST /transaction { items, idempotency_key }
8. Backend: atomic debit buyer, credit cashier, create transaction + items
9. Backend → ESP: { success, new_balance, receipt_no }
10. ESP: beep OK, print/show receipt, update LCD
11. Backend broadcasts to admin:dashboard (live update)
```

### 10.2 Offline Path
```
1-6. Same as above
7. ESP queues to SPIFFS (pending)
8. ESP shows "Offline - Queued" + queue count
9. On WiFi restore: ESP flushes queue batch
10. Backend processes each with idempotency_key
12. Backend responds per-item: success/duplicate/void
13. ESP removes succeeded, retries failed (max 3x)
14. Items >5 min → auto-void, notify on next LCD
```

### 10.3 Idempotency
- **Key format:** `{device_id}-{timestamp_ms}-{random4}`
- **Server:** UNIQUE constraint on transactions.idempotency_key
- **Client:** Retry with same key until success/duplicate

---

## 11. Security Considerations

| Threat | Mitigation |
|--------|------------|
| RFID cloning | UID only identifies card; balance checked server-side |
| Replay attack | Idempotency keys + timestamp validation (max 5 min drift) |
| Console theft | Device registration required; admin can revoke api_key |
| Sniffing | All traffic HTTPS/WSS; api_key rotated on credential change |
| SQL injection | Eloquent ORM + parameterized queries |
| XSS | Blade auto-escape + Alpine.js safe bindings |
| CSRF | Sanctum CSRF protection on web routes |
| Brute force | Rate limiting (5/min/login, 30/min/api) |

---

## 12. Deployment & Operations

### 12.1 Environments
| Env | URL | Database | Purpose |
|-----|-----|----------|---------|
| Local | http://localhost | SQLite | Development |
| Staging | https://staging.kasir.local | Supabase (staging) | QA |
| Production | https://kasir.sekolah.sch.id | Supabase (prod) | Live |

### 12.2 Git Branching
- `main` — Production ready
- `integration/kasir-rfid-v1` — Integration branch for v1
- `ticket-XX-*` — Feature branches per ticket

### 12.3 Backup Strategy
- **Database:** Supabase daily Point-in-Time Recovery (7 days)
- **Code:** GitHub (this repo) — manual push after each ticket
- **Firmware:** GitHub Releases tagged `firmware-v{X.Y.Z}`

---

## 13. Implementation Tickets (14 Total)

| # | Ticket | Branch | Status |
|---|--------|--------|--------|
| 01 | Bootstrap Laravel 11 + Supabase + Schema | `main` | ✅ Done |
| 02 | Admin Login + Shell Dashboard | `ticket-02-admin-login` | ✅ Done |
| 03 | Console CRUD + Credential Rotation | `ticket-03-console-crud` | ⏳ Pending |
| 04 | Menu Management (Category + Item) | `ticket-04-menu-crud` | ⏳ Pending |
| 05 | User Management + RFID Assign | `ticket-05-user-rfid` | ⏳ Pending |
| 06 | ESP8266 Firmware (PlatformIO) | `ticket-06-firmware` | ⏳ Pending |
| 07 | Transaction API + Idempotency | `ticket-07-transaction-api` | ⏳ Pending |
| 08 | Supabase Realtime Integration | `ticket-08-realtime` | ⏳ Pending |
| 09 | Offline Queue + Auto-void | `ticket-09-offline-queue` | ⏳ Pending |
| 10 | Reports + Export | `ticket-10-reports` | ⏳ Pending |
| 11 | Audit Logs + Admin Settings | `ticket-11-audit-settings` | ⏳ Pending |
| 12 | Forgot Password (Admin Code) | `ticket-12-forgot-password` | ⏳ Pending |
| 13 | OTA Firmware Update | `ticket-13-ota` | ⏳ Pending |
| 14 | E2E Testing + Documentation | `ticket-14-e2e-docs` | ⏳ Pending |

---

## 14. Configuration Reference

### 14.1 Laravel `.env` (Production)
```env
APP_NAME="Kasir Skanilan"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://kasir.sekolah.sch.id

DB_CONNECTION=pgsql
DB_HOST=db.xxxxxxxxxxxxx.supabase.co
DB_PORT=5432
DB_DATABASE=postgres
DB_USERNAME=postgres
DB_PASSWORD=${SUPABASE_DB_PASSWORD}
DB_SSLMODE=require

SESSION_DRIVER=database
QUEUE_CONNECTION=database
CACHE_STORE=database

SUPABASE_URL=https://xxxxxxxxxxxxx.supabase.co
SUPABASE_ANON_KEY=${SUPABASE_ANON_KEY}
SUPABASE_SERVICE_ROLE_KEY=${SUPABASE_SERVICE_ROLE_KEY}

MAIL_MAILER=log
```

### 14.2 ESP8266 `platformio.ini`
```ini
[env:esp8266]
platform = espressif8266
board = nodemcuv2
framework = arduino
monitor_speed = 115200
lib_deps =
    arduino-libraries/ArduinoJson@^7.0
    knolleary/PubSubClient@^2.8
    bblanchon/ArduinoHttpClient@^0.4
build_flags =
    -D DEVICE_ID=\"ESP-${MAC}\"
    -D FIRMWARE_VERSION=\"1.0.0\"
board_build.filesystem = spiffs
board_build.flash_mode = dio
```

---

## 15. Testing Strategy

| Layer | Tool | Coverage Target |
|-------|------|-----------------|
| Unit (PHP) | Pest/PHPUnit | 80% models, services |
| Feature (HTTP) | Pest | 100% API endpoints |
| Browser (Admin) | Laravel Dusk | Critical paths |
| Firmware | Unity + Wokwi CI | Hardware-in-loop |
| Integration | GitHub Actions | Full flow on PR |

---

## 16. Glossary

| Term | Definition |
|------|------------|
| **Console** | Terminal kasir fisik (ESP8266 + RFID + LCD) |
| **Cashier** | Operator console (role=user, device_id linked) |
| **Student** | Pembeli (role=student, balance_cents) |
| **Device ID** | Unique ID per ESP8266 (format: `ESP-{MAC}`) |
| **API Key** | 64-char hex, auth untuk console→backend |
| **Idempotency Key** | Client-generated unique key per transaction |
| **SPIFFS** | SPI Flash File System (ESP8266 persistent storage) |
| **RLS** | Row Level Security (PostgreSQL policy) |
| **OTA** | Over-The-Air firmware update |