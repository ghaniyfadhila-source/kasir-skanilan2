#pragma once

#include <Arduino.h>
#include <vector>

/**
 * Antrean tap RFID yang gagal terkirim karena perangkat offline (Ticket 10).
 *
 * Format: JSON Lines di LittleFS — satu baris = satu record. Format ini
 * dipakai supaya append saat online-lagi tetap cepat (tidak perlu rewrite
 * seluruh file), dan file yang korup hanya merusak satu baris, bukan semua.
 *
 * Tap yang lebih tua dari TTL dibatalkan otomatis supaya kasir tidak punya
 * order menggantung yang sudah tidak mungkin dibayar setelah offline lama.
 * Record yang dibatalkan TIDAK langsung dihapus: perangkat masih perlu
 * melaporkannya ke server (`status: "voided"`) supaya order di server ikut
 * ditutup. Baru setelah server menjawab, record dibuang.
 */

/// 5 menit: sesuai SPEC §Ticket 10.
static constexpr uint32_t QUEUE_TTL_SECONDS = 5UL * 60UL;

/// Batas ukuran file 500 KB supaya tidak menghabiskan LittleFS.
static constexpr uint32_t QUEUE_MAX_BYTES = 500UL * 1024UL;

/// Ambang 90% -> tolak tap baru supaya antrean tidak menimpa file system.
static constexpr float QUEUE_FULL_RATIO = 0.90f;

/// Setelah 3 kali ditolak server, record berhenti dicoba otomatis.
static constexpr uint8_t QUEUE_MAX_RETRIES = 3;

enum class QueueResult : uint8_t {
    Ok,
    Full,
    WriteFailed,
    NotInitialised,
};

enum class RecordStatus : uint8_t {
    Pending,  // belum terkirim
    Voided,   // sudah dibatalkan (basi), menunggu dilaporkan ke server
    Failed,   // ditolak server 3x, menunggu tindakan admin
};

struct QueueRecord {
    String idempotencyKey;
    String rfidUid;
    String orderId;
    uint32_t createdAt = 0;    ///< epoch detik; 0 = jam belum diketahui
    uint32_t createdAtMs = 0;  ///< millis() saat dibuat (cadangan satu boot)
    uint32_t bootId = 0;       ///< pembeda antar-reboot
    uint8_t retryCount = 0;
    RecordStatus status = RecordStatus::Pending;
};

class TapQueue
{
public:
    bool begin();

    /// Tambahkan tap baru ke antrean.
    QueueResult enqueue(const QueueRecord &record);

    /// Muat ulang seluruh antrean dari LittleFS.
    void loadAll();

    size_t pendingCount() const { return m_pendingCount; }
    size_t voidedCount() const { return m_voidedCount; }
    size_t failedCount() const { return m_failedCount; }
    size_t totalCount() const { return m_records.size(); }
    uint32_t sizeBytes() const { return m_sizeBytes; }
    bool isFull() const;

    /// Ambil record pending tertua, urut berdasarkan waktu dibuat.
    const QueueRecord *oldestPending() const;

    /**
     * Kumpulkan record yang perlu dikirim ke server: pending dan voided,
     * urut dari yang paling lama. Record `Failed` tidak ikut.
     *
     * @return jumlah record yang dimasukkan ke `out`
     */
    size_t collectBatch(size_t limit, std::vector<QueueRecord> &out) const;

    /// Hapus record (sudah terkirim / sudah dilaporkan).
    void remove(const String &idempotencyKey);

    /// Naikkan retry_count; setelah QUEUE_MAX_RETRIES record jadi `Failed`.
    void bumpRetry(const String &idempotencyKey);

    /**
     * Batalkan record yang lebih tua dari QUEUE_TTL_SECONDS.
     *
     * @param nowEpoch epoch sekarang (0 kalau belum diketahui)
     * @param nowMs    millis() sekarang
     * @param bootId   id boot saat ini
     * @param firstVoidedKey diisi key record pertama yang dibatalkan (untuk LCD)
     * @return jumlah record yang dibatalkan
     */
    uint16_t autoVoidExpired(uint32_t nowEpoch, uint32_t nowMs, uint32_t bootId,
                             String *firstVoidedKey = nullptr);

    /// Buang seluruh isi antrean (perintah admin `queue.clear`).
    uint16_t clearAll();

    const String &lastError() const { return m_lastError; }

private:
    String serialize(const QueueRecord &r) const;
    bool deserialize(const String &line, QueueRecord &out) const;
    bool rewrite();
    void recount();

    /// Buang record voided saat antrean hampir penuh (ruang darurat).
    uint16_t purgeVoided();

    std::vector<QueueRecord> m_records;
    uint32_t m_sizeBytes = 0;
    size_t m_pendingCount = 0;
    size_t m_voidedCount = 0;
    size_t m_failedCount = 0;
    bool m_initialised = false;
    String m_lastError;
};
