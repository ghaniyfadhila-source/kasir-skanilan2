#pragma once

#include <Arduino.h>
#include <vector>

#include "queue.h"
#include "state.h"

/**
 * Koneksi WiFi + klien REST ke backend Kasir Skanilan.
 *
 * Semua pemanggilan memakai endpoint REST:
 *   POST /api/console/heartbeat
 *   POST /api/console/rfid-tap
 *   POST /api/console/sync          (Ticket 10: antrean offline)
 *   POST /api/console/commands/ack  (Ticket 10: balasan perintah admin)
 *   GET  /api/console/balance/{uid}
 *   GET  /api/console/pending-order
 *
 * Firmware sengaja TIDAK berlangganan WebSocket: klien Phoenix tidak muat di
 * heap ~40 KB ESP8266 dan membuat kasir bergantung pada koneksi internet.
 * Perintah admin dikirim sebagai bagian dari respons heartbeat/sync.
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

/// Perintah admin yang ikut pada respons heartbeat/sync.
struct DeviceCommand {
    String id;
    String command;  // mis. "queue.clear"
};

/// Hasil satu record di dalam batch sync.
struct SyncResultItem {
    String idempotencyKey;
    String status;  // success|duplicate|void|discard|...|error
    String message;
    String receiptNo;
    String buyerName;
    long totalCents = 0;
    long newBalanceCents = 0;
};

struct SyncResult {
    bool transportOk = false;  // server menjawab 2xx
    bool ok = false;           // envelope `success: true`
    uint32_t serverEpoch = 0;
    std::vector<SyncResultItem> items;
    std::vector<DeviceCommand> commands;
    String error;
};

class Network
{
public:
    /// Hubungkan ke WiFi. Non-blocking; cek progres lewat status().
    bool begin();
    bool tick();

    bool online() const { return m_online; }
    OfflineReason offlineReason() const { return m_offlineReason; }
    int wifiRssi();

    /**
     * Heartbeat ke backend, sekaligus memeriksa apakah device masih terdaftar.
     *
     * @param serverEpoch diisi epoch server (untuk jam perangkat)
     * @param commands    diisi perintah admin yang belum dijalankan
     */
    bool sendHeartbeat(uint32_t *serverEpoch = nullptr,
                       std::vector<DeviceCommand> *commands = nullptr,
                       size_t queuePending = 0, size_t queueFailed = 0);

    TapResponse rfidTap(const String &rfidUid, const String &orderId,
                        const String &idempotencyKey);

    /// Kirim beberapa tap sekaligus ke /api/console/sync.
    SyncResult syncTaps(const std::vector<QueueRecord> &records,
                        size_t queuePending, size_t queueFailed);

    /// Beri tahu server bahwa perintah sudah dijalankan.
    bool ackCommand(const String &id, bool ok, const String &message = "");

    bool fetchBalance(const String &rfidUid, long &balanceCents, String &name);

    /// Ambil status order (dipakai untuk polling saat mode offline/lambat).
    OrderStatus fetchOrderStatus(const String &orderId);

    /// Buka captive portal untuk memasukkan SSID/password WiFi.
    void startConfigPortal();

    uint32_t lastHeartbeatAt() const { return m_lastHeartbeatAt; }

private:
    int httpWithTimeout(const String &method, const String &path, const String &body,
                        String &out, uint32_t timeoutMs = 0);

    bool m_online = false;
    OfflineReason m_offlineReason = OfflineReason::None;
    uint32_t m_lastHeartbeatAt = 0;
    uint32_t m_lastOkAt = 0;
    uint8_t m_failStreak = 0;
};
