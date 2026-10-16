#include "queue.h"

#include <ArduinoJson.h>
#include <LittleFS.h>
#include <algorithm>

namespace {

const char *QUEUE_PATH = "/queue/taps.jsonl";

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

    Serial.printf("[queue] siap: %u pending, %u byte\n",
                  (unsigned)m_pendingCount, (unsigned)m_sizeBytes);

    return true;
}

String TapQueue::serialize(const QueueRecord &r) const {
    JsonDocument doc;
    doc["idempotency_key"] = r.idempotencyKey;
    doc["rfid_uid"] = r.rfidUid;
    doc["order_id"] = r.orderId;
    doc["created_at"] = r.createdAt;
    doc["retry_count"] = r.retryCount;
    doc["status"] = (r.status == RecordStatus::Pending) ? "pending" : "voided";

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
    out.retryCount = doc["retry_count"] | 0u;
    out.status = String(doc["status"] | "pending") == "voided"
                     ? RecordStatus::Voided
                     : RecordStatus::Pending;

    return true;
}

void TapQueue::loadAll() {
    m_records.clear();
    m_pendingCount = 0;

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
    for (const auto &r : m_records) {
        if (r.status == RecordStatus::Pending) {
            m_pendingCount++;
        }
    }
}

QueueResult TapQueue::enqueue(const QueueRecord &record) {
    if (!m_initialised) {
        return QueueResult::NotInitialised;
    }

    if (isFull()) {
        m_lastError = "Antrean penuh";
        return QueueResult::Full;
    }

    // Batch rewrite akan membuat file utuh; append lebih murah dan aman.
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

    if (record.status == RecordStatus::Pending) {
        m_pendingCount++;
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
        if (r.idempotencyKey == idempotencyKey) {
            r.retryCount++;
            rewrite();
            Serial.printf("[queue] retry %s -> %u\n",
                          idempotencyKey.c_str(), (unsigned)r.retryCount);
            return;
        }
    }
}

uint16_t TapQueue::autoVoidExpired() {
    if (!m_initialised) {
        return 0;
    }

    const uint32_t now = millis();
    uint16_t voided = 0;

    for (auto &r : m_records) {
        if (r.status != RecordStatus::Pending) {
            continue;
        }

        // millis() wrap-around setelah ~49 hari: selisih unsigned aman.
        if ((now - r.createdAt) > QUEUE_TTL_MS) {
            r.status = RecordStatus::Voided;
            voided++;
        }
    }

    if (voided > 0) {
        rewrite();
        recount();
        Serial.printf("[queue] auto-void %u tap (> %lu ms)\n",
                      (unsigned)voided, (unsigned long)QUEUE_TTL_MS);
    }

    return voided;
}

uint16_t TapQueue::clearAll() {
    if (!m_initialised) {
        return 0;
    }

    size_t removed = 0;

    for (auto &r : m_records) {
        if (r.status == RecordStatus::Pending) {
            removed++;
        }
    }

    m_records.erase(
        std::remove_if(m_records.begin(), m_records.end(),
                       [](const QueueRecord &r) {
                           return r.status == RecordStatus::Pending;
                       }),
        m_records.end());

    rewrite();
    recount();

    Serial.printf("[queue] bersihkan %u pending\n", (unsigned)removed);
    return (uint16_t)removed;
}