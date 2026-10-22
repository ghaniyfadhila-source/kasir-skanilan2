#include "queue.h"

#include <ArduinoJson.h>
#include <LittleFS.h>
#include <algorithm>

namespace {

const char *QUEUE_PATH = "/queue/taps.jsonl";

bool statusFromString(const String &value, RecordStatus &out) {
    if (value == "voided") {
        out = RecordStatus::Voided;
        return true;
    }
    if (value == "failed") {
        out = RecordStatus::Failed;
        return true;
    }
    out = RecordStatus::Pending;
    return true;
}

const char *statusToString(RecordStatus status) {
    switch (status) {
        case RecordStatus::Voided:
            return "voided";
        case RecordStatus::Failed:
            return "failed";
        default:
            return "pending";
    }
}

/**
 * Umur record dalam detik.
 *
 * Prioritas: epoch (tahan reboot) -> millis() kalau record dibuat pada boot
 * yang sama. Record dari boot sebelumnya yang jamnya tidak diketahui
 * dikembalikan false: umurnya tidak bisa dibuktikan, jadi lebih aman
 * dianggap basi daripada menggantung selamanya di antrean.
 */
bool recordAgeSeconds(const QueueRecord &r, uint32_t nowEpoch, uint32_t nowMs,
                      uint32_t bootId, uint32_t &age) {
    if (r.createdAt != 0 && nowEpoch != 0) {
        // Jam bisa mundur (koreksi NTP/server); jangan hasilkan umur negatif.
        age = nowEpoch > r.createdAt ? nowEpoch - r.createdAt : 0;
        return true;
    }

    if (r.bootId != 0 && r.bootId == bootId && nowMs >= r.createdAtMs) {
        age = (nowMs - r.createdAtMs) / 1000UL;
        return true;
    }

    return false;
}

} // namespace

bool TapQueue::begin() {
    if (!LittleFS.begin()) {
        m_lastError = "LittleFS gagal dibuka";
        Serial.println(F("[queue] LittleFS gagal dibuka"));
        return false;
    }

    if (!LittleFS.exists(QUEUE_PATH)) {
        // Buat file kosong supaya enqueue() bisa langsung append.
        File f = LittleFS.open(QUEUE_PATH, "w");
        if (f) {
            f.close();
        }
    }

    m_initialised = true;
    loadAll();

    Serial.printf("[queue] siap: %u pending, %u voided, %u failed, %u byte\n",
                  (unsigned)m_pendingCount, (unsigned)m_voidedCount,
                  (unsigned)m_failedCount, (unsigned)m_sizeBytes);

    return true;
}

String TapQueue::serialize(const QueueRecord &r) const {
    JsonDocument doc;
    doc["idempotency_key"] = r.idempotencyKey;
    doc["rfid_uid"] = r.rfidUid;
    doc["order_id"] = r.orderId;
    doc["created_at"] = r.createdAt;
    doc["created_at_ms"] = r.createdAtMs;
    doc["boot_id"] = r.bootId;
    doc["retry_count"] = r.retryCount;
    doc["status"] = statusToString(r.status);

    String out;
    serializeJson(doc, out);
    return out;
}

bool TapQueue::deserialize(const String &line, QueueRecord &out) const {
    JsonDocument doc;
    if (deserializeJson(doc, line)) {
        return false;
    }

    if (!doc["idempotency_key"].is<const char *>()) {
        return false;
    }

    out.idempotencyKey = doc["idempotency_key"] | "";
    out.rfidUid = doc["rfid_uid"] | "";
    out.orderId = doc["order_id"] | "";
    out.createdAt = doc["created_at"] | 0u;
    out.createdAtMs = doc["created_at_ms"] | 0u;
    out.bootId = doc["boot_id"] | 0u;
    out.retryCount = doc["retry_count"] | 0u;

    String status = doc["status"] | "pending";
    statusFromString(status, out.status);

    return true;
}

void TapQueue::loadAll() {
    m_records.clear();
    m_pendingCount = 0;
    m_voidedCount = 0;
    m_failedCount = 0;
    m_sizeBytes = 0;

    if (!LittleFS.exists(QUEUE_PATH)) {
        return;
    }

    File f = LittleFS.open(QUEUE_PATH, "r");
    if (!f) {
        return;
    }

    m_sizeBytes = f.size();

    while (f.available()) {
        String line = f.readStringUntil('\n');
        line.trim();

        if (line.length() == 0) {
            continue;
        }

        QueueRecord r;
        if (!deserialize(line, r)) {
            // Baris korup dilewati; file tidak ikut dibuang.
            Serial.println(F("[queue] satu baris korup, dilewati"));
            continue;
        }

        m_records.push_back(r);
    }

    f.close();
    recount();
}

void TapQueue::recount() {
    m_pendingCount = 0;
    m_voidedCount = 0;
    m_failedCount = 0;

    for (const auto &r : m_records) {
        switch (r.status) {
            case RecordStatus::Pending:
                m_pendingCount++;
                break;
            case RecordStatus::Voided:
                m_voidedCount++;
                break;
            case RecordStatus::Failed:
                m_failedCount++;
                break;
        }
    }
}

bool TapQueue::isFull() const {
    return m_sizeBytes >= (uint32_t)(QUEUE_MAX_BYTES * QUEUE_FULL_RATIO);
}

QueueResult TapQueue::enqueue(const QueueRecord &record) {
    if (!m_initialised) {
        return QueueResult::NotInitialised;
    }

    if (isFull()) {
        // Record voided hanya bersifat laporan; kalau antrean hampir penuh,
        // membuangnya lebih baik daripada menolak tap baru.
        purgeVoided();

        if (isFull()) {
            m_lastError = "Antrean penuh";
            return QueueResult::Full;
        }
    }

    // Append lebih murah dan aman daripada menulis ulang seluruh file.
    File f = LittleFS.open(QUEUE_PATH, "a");
    if (!f) {
        m_lastError = "Gagal membuka file antrean";
        return QueueResult::WriteFailed;
    }

    String line = serialize(record) + "\n";
    size_t written = f.print(line);
    f.close();

    if (written != line.length()) {
        m_lastError = "Tulis tidak lengkap";
        return QueueResult::WriteFailed;
    }

    m_records.push_back(record);
    m_sizeBytes += written;

    switch (record.status) {
        case RecordStatus::Pending:
            m_pendingCount++;
            break;
        case RecordStatus::Voided:
            m_voidedCount++;
            break;
        case RecordStatus::Failed:
            m_failedCount++;
            break;
    }

    Serial.printf("[queue] enqueue %s (%u pending)\n",
                  record.idempotencyKey.c_str(), (unsigned)m_pendingCount);

    return QueueResult::Ok;
}

const QueueRecord *TapQueue::oldestPending() const {
    const QueueRecord *oldest = nullptr;

    for (const auto &r : m_records) {
        if (r.status != RecordStatus::Pending) {
            continue;
        }
        if (!oldest || r.createdAt < oldest->createdAt) {
            oldest = &r;
        }
    }

    return oldest;
}

size_t TapQueue::collectBatch(size_t limit, std::vector<QueueRecord> &out) const {
    out.clear();

    if (limit == 0) {
        return 0;
    }

    for (const auto &r : m_records) {
        if (r.status == RecordStatus::Failed) {
            continue;
        }

        out.push_back(r);

        if (out.size() >= limit) {
            break;
        }
    }

    return out.size();
}

bool TapQueue::rewrite() {
    File f = LittleFS.open(QUEUE_PATH, "w");
    if (!f) {
        m_lastError = "Gagal menulis ulang antrean";
        return false;
    }

    uint32_t bytes = 0;
    for (const auto &r : m_records) {
        String line = serialize(r) + "\n";
        f.print(line);
        bytes += line.length();
    }

    f.close();
    m_sizeBytes = bytes;

    return true;
}

void TapQueue::remove(const String &idempotencyKey) {
    if (!m_initialised) {
        return;
    }

    size_t before = m_records.size();

    m_records.erase(
        std::remove_if(m_records.begin(), m_records.end(),
                       [&](const QueueRecord &r) {
                           return r.idempotencyKey == idempotencyKey;
                       }),
        m_records.end());

    if (m_records.size() != before) {
        rewrite();
        recount();
        Serial.printf("[queue] hapus %s\n", idempotencyKey.c_str());
    }
}

void TapQueue::bumpRetry(const String &idempotencyKey) {
    for (auto &r : m_records) {
        if (r.idempotencyKey != idempotencyKey) {
            continue;
        }

        if (r.retryCount < 255) {
            r.retryCount++;
        }

        if (r.retryCount >= QUEUE_MAX_RETRIES) {
            r.status = RecordStatus::Failed;
        }

        rewrite();
        recount();

        Serial.printf("[queue] retry %s -> %u (%s)\n",
                      idempotencyKey.c_str(), (unsigned)r.retryCount,
                      statusToString(r.status));
        return;
    }
}

uint16_t TapQueue::autoVoidExpired(uint32_t nowEpoch, uint32_t nowMs,
                                   uint32_t bootId, String *firstVoidedKey) {
    if (!m_initialised) {
        return 0;
    }

    uint16_t voided = 0;

    for (auto &r : m_records) {
        if (r.status != RecordStatus::Pending) {
            continue;
        }

        uint32_t age = 0;
        bool known = recordAgeSeconds(r, nowEpoch, nowMs, bootId, age);

        if (known && age <= QUEUE_TTL_SECONDS) {
            continue;
        }

        r.status = RecordStatus::Voided;
        voided++;

        if (firstVoidedKey != nullptr && firstVoidedKey->length() == 0) {
            *firstVoidedKey = r.idempotencyKey;
        }
    }

    if (voided > 0) {
        rewrite();
        recount();
        Serial.printf("[queue] auto-void %u tap (> %lu detik)\n",
                      (unsigned)voided, (unsigned long)QUEUE_TTL_SECONDS);
    }

    return voided;
}

uint16_t TapQueue::purgeVoided() {
    if (!m_initialised) {
        return 0;
    }

    size_t before = m_records.size();

    m_records.erase(
        std::remove_if(m_records.begin(), m_records.end(),
                       [](const QueueRecord &r) {
                           return r.status == RecordStatus::Voided;
                       }),
        m_records.end());

    size_t removed = before - m_records.size();

    if (removed > 0) {
        rewrite();
        recount();
        Serial.printf("[queue] buang %u record voided untuk ruang\n", (unsigned)removed);
    }

    return (uint16_t)removed;
}

uint16_t TapQueue::clearAll() {
    if (!m_initialised) {
        return 0;
    }

    uint16_t removed = (uint16_t)m_records.size();

    m_records.clear();
    rewrite();
    recount();

    Serial.printf("[queue] bersihkan %u record\n", (unsigned)removed);
    return removed;
}
