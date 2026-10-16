#pragma once

#include <Arduino.h>
#include <vector>

/**
 * Antrean tap RFID yang gagal terkirim karena perangkat offline.
 *
 * Format: JSON Lines di LittleFS — satu baris = satu record. Format ini
 * dipakai supaya append saat online-lagi tetap cepat (tidak perlu rewrite
 * seluruh file), dan file yang korup hanya merusak satu baris, bukan semua.
 *
 * Tap yang lebih tua dari TTL dibatalkan otomatis supaya kasir tidak punya
 * order menggantung yang sudah tidak mungkin dibayar setelah offline lama.
 */

/// 5 menit: sesuai SPEC §Ticket 10.
static constexpr uint32_t QUEUE_TTL_MS = 5UL * 60UL * 1000UL;

/// Batas ukuran file 500 KB supaya tidak menghabiskan LittleFS.
static constexpr uint32_t QUEUE_MAX_BYTES = 500UL * 1024UL;

/// Ambang 90% -&gt; tolak tap baru supaya antrean tidak menimpa fileFS.
static constexpr float QUEUE_FULL_RATIO = 0.90f;

enum class QueueResult : uint8_t {
    Ok,
    Full,
    WriteFailed,
    NotInitialised,
};

enum class RecordStatus : uint8_t {
    Pending,
    Voided,
};

struct QueueRecord {
    String idempotencyKey;
    String rfidUid;
    String orderId;
    uint32_t createdAt;   // millis() saat dibuat
    uint8_t retryCount;
    RecordStatus status;
};

class TapQueue {
public:
    bool begin();

    /// Tambahkan tap baru ke antrean.
    QueueResult enqueue(const QueueRecord &record);

    /// Baca seluruh antrean (hanya record pending/voided).
    void loadAll();

    size_t pendingCount() const { return m_pendingCount; }
    size_t totalCount() const { return m_records.size(); }
    uint32_t sizeBytes() const { return m_sizeBytes; }
    bool isFull() const { return m_sizeBytes >= (uint32_t)(QUEUE_MAX_BYTES * QUEUE_FULL_RATIO); }

    /// Ambil record pending tertua, urut berdasarkan waktu dibuat.
    const QueueRecord *oldestPending() const;

    /// Hapus record (sudah terkirim / selesai).
    void remove(const String &idempotencyKey);

    /// Naikkan retry_count untuk record tertentu.
    void bumpRetry(const String &idempotencyKey);

    /**
     * Batalkan record yang lebih tua dari QUEUE_TTL_MS.
     * @return jumlah record yang dibatalkan.
     */
    uint16_t autoVoidExpired();

    /// Batalkan semua record pending (mis. dari perintah admin).
    uint16_t clearAll();

    const String &lastError() const { return m_lastError; }

private:
    String serialize(const QueueRecord &r) const;
    bool deserialize(const String &line, QueueRecord &out) const;
    bool rewrite();
    void recount();

    std::vector<QueueRecord> m_records;
    uint32_t m_sizeBytes = 0;
    size_t m_pendingCount = 0;
    bool m_initialised = false;
    String m_lastError;
};