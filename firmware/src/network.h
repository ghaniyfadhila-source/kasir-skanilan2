#pragma once

#include <Arduino.h>
#include "state.h"

/**
 * Koneksi WiFi + klien REST ke backend Kasir Skanilan.
 *
 * Semua pemanggilan memakai endpoint REST yang sudah ada di Ticket 08:
 *   POST /api/console/heartbeat
 *   GET  /api/console/balance/{uid}
 *   POST /api/console/rfid-tap
 *
 * Realtime (WebSocket) belum dipakai karena Ticket 09 masih menunggu project
 * Supabase. Sebagai gantinya, order yang sedang menunggu RFID diambil dengan
 * polling `GET /api/cashier/orders/{id}/status` — cukup untuk alur kasir,
 * hanya saja LCD tidak langsung berubah tanpa polling.
 */

struct TapResponse {
    bool ok = false;             // permintaan berhasil dijawab server
    bool duplicate = false;      // tap duplikat (idempotensi)
    String errorCode;
    String message;
    String receiptNo;
    String buyerName;
    long newBalanceCents = 0;
    long totalCents = 0;
};

struct OrderStatus {
    bool found = false;
    String orderId;
    String status;       // awaiting_rfid | completed | voided
    long totalCents = 0;
    long expiresAtMs = 0;
};

class Network {
public:
    /// Hubungkan ke WiFi. Non-blocking; cek progres lewat status().
    bool begin();
    bool tick();

    bool online() const { return m_online; }
    OfflineReason offlineReason() const { return m_offlineReason; }
    int wifiRssi();

    /// Heartbeat ke backend, sekaligus memeriksa apakah device masih terdaftar.
    bool sendHeartbeat();

    TapResponse rfidTap(const String &rfidUid, const String &orderId,
                        const String &idempotencyKey);

    bool fetchBalance(const String &rfidUid, long &balanceCents, String &name);

    /// Ambil status order (dipakai untuk polling saat mode offline/lambat).
    OrderStatus fetchOrderStatus(const String &orderId);

    /// Buka captive portal untuk memasukkan SSID/password WiFi.
    void startConfigPortal();

    uint32_t lastHeartbeatAt() const { return m_lastHeartbeatAt; }

private:
    int httpWithTimeout(const String &method, const String &path, const String &body, String &out);

    bool m_online = false;
    OfflineReason m_offlineReason = OfflineReason::None;
    uint32_t m_lastHeartbeatAt = 0;
    uint32_t m_lastOkAt = 0;
    uint8_t m_failStreak = 0;
};