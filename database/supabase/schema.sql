-- Supabase Schema for Kasir Skanilan 2
-- Run this in Supabase SQL Editor

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
    device_id VARCHAR(17), -- FK consoles.device_id, diisi setelah tabel consoles dibuat
    created_at TIMESTAMPTZ DEFAULT now(),
    updated_at TIMESTAMPTZ DEFAULT now()
);

-- RFID Cards
CREATE TABLE rfid_cards (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    rfid_uid CHAR(8) UNIQUE NOT NULL,
    label VARCHAR(100),
    is_active BOOLEAN DEFAULT true,
    created_at TIMESTAMPTZ DEFAULT now(),
    revoked_at TIMESTAMPTZ
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

-- Cashier ↔ Console binding (setelah tabel consoles ada)
ALTER TABLE users
    ADD CONSTRAINT users_device_id_fkey
    FOREIGN KEY (device_id) REFERENCES consoles(device_id) ON DELETE SET NULL;
CREATE INDEX idx_users_device_id ON users(device_id);
CREATE UNIQUE INDEX idx_users_device_id_unique ON users(device_id)
    WHERE device_id IS NOT NULL AND role = 'cashier';

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
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
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

-- ========================================================================
-- 5. Realtime (Ticket 09)
-- ========================================================================
-- Di UI Supabase versi baru tidak ada menu "Realtime" terpisah; Realtime
-- dikelola lewat PUBLICATION. Tabel HARUS ada dulu sebelum bisa ditambahkan,
-- jadi blok ini wajib dijalankan SETELAH seluruh CREATE TABLE di atas.
--
-- Kalau publication `supabase_realtime` belum ada (project sangat baru),
-- buat dulu. Publication bawaan Supabase biasanya sudah ada.

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_publication WHERE pubname = 'supabase_realtime') THEN
        CREATE PUBLICATION supabase_realtime;
    END IF;
END
$$;

-- Tambahkan tabel ke publication. Error "duplicate object" diabaikan kalau
-- tabel sudah terdaftar, jadi blok ini aman dijalankan ulang.
DO $$
DECLARE
    t TEXT;
    target_tables TEXT[] := ARRAY[
        'consoles', 'menu_items', 'transactions', 'rfid_cards', 'users'
    ];
BEGIN
    FOREACH t IN ARRAY target_tables LOOP
        BEGIN
            EXECUTE format(
                'ALTER PUBLICATION supabase_realtime ADD TABLE %I', t
            );
        EXCEPTION WHEN duplicate_object THEN
            RAISE NOTICE 'tabel % sudah ada di publication supabase_realtime', t;
        END;
    END LOOP;
END
$$;

-- ========================================================================
-- 6. Storage bucket untuk firmware (Ticket 14 - OTA)
-- ========================================================================
-- Bucket WAJIB private: firmware hanya boleh diunduh lewat signed URL dari
-- backend memakai service_role key.
INSERT INTO storage.buckets (id, name, public)
VALUES ('firmware', 'firmware', false)
ON CONFLICT (id) DO NOTHING;

-- ========================================================================
-- Verifikasi (jalankan terpisah, masing-masing query harusnya mengembalikan baris)
-- ========================================================================
-- SELECT pubname, tablename FROM pg_publication_tables WHERE pubname = 'supabase_realtime';
-- SELECT id, name, public FROM storage.buckets WHERE id = 'firmware';