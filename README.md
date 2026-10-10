# Kasir Skanilan 2 — RFID-based POS System

Sistem kasir digital berbasis RFID untuk kantin sekolah. Multi-console, multi-kasir, skala menengah.

## Tech Stack

- **Backend**: Laravel 11 (PHP 8.3)
- **Database**: Supabase (PostgreSQL) + Realtime
- **Frontend**: Blade + Alpine.js + Tailwind CSS
- **Queue**: Database driver
- **Hardware**: ESP8266 + RFID RC522 + I2C LCD + Buzzer

## Setup Local

### Prasyarat
- PHP 8.3+ dengan ekstensi: pdo_pgsql, pgsql, curl, mbstring, openssl, fileinfo, zip, gd, intl
- Composer
- Node.js 18+ & npm (untuk frontend build)
- Akun Supabase (region Singapore/AP-Southeast-1)

### 1. Clone & Install

```bash
git clone https://github.com/ghaniyfadhila-source/kasir-skanilan2.git
cd kasir-skanilan2
composer install
npm install
```

### 2. Supabase Setup

1. Buat project di [supabase.com](https://supabase.com) (region **Singapore/AP-Southeast-1**)
2. Buka **SQL Editor**, jalankan SQL dari `database/supabase/schema.sql` (lihat bawah)
3. Catat: Project URL, Anon Key, Service Role Key
4. Aktifkan Realtime: Database → Replication → Enable pada tabel `transactions`

### 3. Konfigurasi Environment

```bash
cp .env.example .env
php artisan key:generate
```

Edit `.env` dengan kredensial Supabase:

```env
DB_CONNECTION=pgsql
DB_HOST=db.xxxxxxxxxxxxx.supabase.co
DB_PORT=5432
DB_DATABASE=postgres
DB_USERNAME=postgres
DB_PASSWORD=your-supabase-password
DB_SSLMODE=require

SUPABASE_URL=https://xxxxxxxxxxxxxxx.supabase.co
SUPABASE_ANON_KEY=your-anon-key
SUPABASE_SERVICE_ROLE_KEY=your-service-role-key
```

### 4. Migrasi & Seed

```bash
php artisan migrate
php artisan db:seed
```

Akun admin default:
- Email: `admin@kasir.local`
- Password: `password123`

### 5. Frontend Build

```bash
npm run build
# atau development
npm run dev
```

### 6. Jalankan Server

```bash
php artisan serve
# http://localhost:8000
```

### 7. Queue Worker (untuk background jobs)

```bash
php artisan queue:work
```

## Supabase Schema SQL

File: `database/supabase/schema.sql`

```sql
-- Enable UUID extension
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";

-- Users
CREATE TABLE users (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'cashier',
    email_verified_at TIMESTAMPTZ,
    remember_token VARCHAR(100),
    balance_cents BIGINT NOT NULL DEFAULT 0,
    created_at TIMESTAMPTZ DEFAULT now(),
    updated_at TIMESTAMPTZ DEFAULT now()
);

-- RFID Cards
CREATE TABLE rfid_cards (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    rfid_uid CHAR(14) UNIQUE NOT NULL,
    label VARCHAR(100),
    is_active BOOLEAN DEFAULT true,
    created_at TIMESTAMPTZ DEFAULT now()
);

-- Consoles
CREATE TABLE consoles (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    device_id VARCHAR(50) UNIQUE NOT NULL,
    name VARCHAR(100) NOT NULL,
    location VARCHAR(255),
    status VARCHAR(20) DEFAULT 'offline',
    last_heartbeat_at TIMESTAMPTZ,
    firmware_version VARCHAR(20),
    ip_address INET,
    wifi_rssi INT,
    is_active BOOLEAN DEFAULT true,
    created_at TIMESTAMPTZ DEFAULT now(),
    updated_at TIMESTAMPTZ DEFAULT now()
);

-- Console Credentials
CREATE TABLE console_credentials (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    console_id UUID NOT NULL REFERENCES consoles(id) ON DELETE CASCADE,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMPTZ DEFAULT now(),
    updated_at TIMESTAMPTZ DEFAULT now()
);

-- Categories
CREATE TABLE categories (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    name VARCHAR(100) NOT NULL UNIQUE,
    sort_order INT DEFAULT 0,
    is_active BOOLEAN DEFAULT true,
    created_at TIMESTAMPTZ DEFAULT now()
);

-- Menu Items
CREATE TABLE menu_items (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    category_id UUID REFERENCES categories(id) ON DELETE SET NULL,
    name VARCHAR(255) NOT NULL,
    price_cents BIGINT NOT NULL,
    stock INT DEFAULT 0,
    image_url TEXT,
    is_active BOOLEAN DEFAULT true,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMPTZ DEFAULT now(),
    updated_at TIMESTAMPTZ DEFAULT now()
);

-- Transactions
CREATE TABLE transactions (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    console_id UUID NOT NULL REFERENCES consoles(id),
    user_id UUID REFERENCES users(id) ON DELETE SET NULL,
    cashier_id UUID REFERENCES users(id) ON DELETE SET NULL,
    items JSONB NOT NULL,
    total_cents BIGINT NOT NULL,
    status VARCHAR(20) DEFAULT 'pending',
    idempotency_key VARCHAR(64) UNIQUE NOT NULL,
    error_code VARCHAR(50),
    error_message TEXT,
    created_at TIMESTAMPTZ DEFAULT now(),
    completed_at TIMESTAMPTZ,
    voided_at TIMESTAMPTZ,
    voided_by UUID REFERENCES users(id)
);

CREATE INDEX idx_transactions_console_created ON transactions(console_id, created_at DESC);
CREATE INDEX idx_transactions_user_created ON transactions(user_id, created_at DESC);
CREATE INDEX idx_transactions_status_pending ON transactions(status) WHERE status = 'pending';
CREATE INDEX idx_transactions_idempotency ON transactions(idempotency_key);

-- Audit Logs
CREATE TABLE audit_logs (
    id BIGSERIAL PRIMARY KEY,
    console_id UUID REFERENCES consoles(id),
    user_id UUID REFERENCES users(id),
    action VARCHAR(100) NOT NULL,
    metadata JSONB,
    created_at TIMESTAMPTZ DEFAULT now()
);
CREATE INDEX idx_audit_console_created ON audit_logs(console_id, created_at DESC);

-- Password Resets
CREATE TABLE password_resets (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    code_hash VARCHAR(255) NOT NULL,
    expires_at TIMESTAMPTZ NOT NULL,
    used_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ DEFAULT now()
);

-- Row Level Security (RLS) for anon key (ESP8266)
ALTER TABLE users ENABLE ROW LEVEL SECURITY;
ALTER TABLE rfid_cards ENABLE ROW LEVEL SECURITY;
ALTER TABLE consoles ENABLE ROW LEVEL SECURITY;
ALTER TABLE console_credentials ENABLE ROW LEVEL SECURITY;
ALTER TABLE categories ENABLE ROW LEVEL SECURITY;
ALTER TABLE menu_items ENABLE ROW LEVEL SECURITY;
ALTER TABLE transactions ENABLE ROW LEVEL SECURITY;

-- Anon policies
CREATE POLICY "anon_read_own_console" ON consoles
    FOR SELECT TO anon
    USING (device_id = current_setting('app.current_console_id', true));

CREATE POLICY "anon_read_active_menus" ON menu_items
    FOR SELECT TO anon
    USING (is_active = true);

CREATE POLICY "anon_insert_transaction" ON transactions
    FOR INSERT TO anon
    WITH CHECK (
        console_id = (SELECT id FROM consoles WHERE device_id = current_setting('app.current_console_id', true))
    );
```

## Hardware (ESP8266)

### Wiring (NodeMCU / D1 Mini)

| Komponen | Pin | GPIO |
|----------|-----|------|
| RFID SS (SDA) | D8 | 15 |
| RFID SCK | D5 | 14 |
| RFID MOSI | D7 | 13 |
| RFID MISO | D6 | 12 |
| RFID RST | D3 | 0 |
| LCD I2C SDA | D2 | 4 |
| LCD I2C SCL | D1 | 5 |
| Buzzer | D0 | 16 |

### Library (PlatformIO)

```ini
lib_deps =
    miguelbalboa/MFRC522@^1.4.12
    bblanchon/ArduinoJson@^7.0.4
    mathertel/LiquidCrystal_I2C@^1.1.2
    tzapu/WiFiManager@^2.0.15-alpha
    links2004/arduinoWebSockets@^2.6.1
```

### Konfigurasi WiFi

Boot pertama → ESP8266 buat AP `KASIR-SETUP-XXXX` (XXXX = Chip ID). Sambung ke AP, buka `192.168.4.1`, masukkan WiFi kantin. Konfigurasi tersimpan otomatis.

### Device ID

Setiap console punya `device_id` (format `KSR-001`) yang didaftarkan di admin panel. ESP8266 membaca `device_id` dari SPIFFS (`console_id.txt`).

## Arsitektur Realtime

Channel Supabase: `console:{device_id}`

| Event | Arah | Payload |
|-------|------|---------|
| `new_transaction` | Web → ESP8266 | `{idempotency_key, total_cents, items[], timeout_ms}` |
| `tap_received` | ESP8266 → Web | `{idempotency_key, rfid_uid, timestamp}` |
| `transaction_result` | Web → ESP8266 | `{idempotency_key, success, user_id, new_balance_cents, error_code?, error_msg?}` |
| `heartbeat` | ESP8266 → Web | `{device_id, firmware_ver, uptime_ms, wifi_rssi, heap_free}` |

## Deployment VPS (Migrasi dari Supabase)

```bash
# 1. Dump Supabase
pg_dump -h db.xxxxx.supabase.co -U postgres -d postgres > backup.sql

# 2. Restore ke VPS PostgreSQL
psql -h localhost -U postgres -d kasir < backup.sql

# 3. Update .env DB_*
# 4. Jalankan migrasi ulang jika perlu
php artisan migrate --force
```

## Backup

Backup dilakukan via GitHub repo ini. Supabase auto-backup harian (retensi 7 hari di free tier).

## Lisensi

Internal project SMK PPLG.