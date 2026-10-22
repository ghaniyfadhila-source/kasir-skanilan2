#include "clock.h"

#include <ArduinoJson.h>
#include <LittleFS.h>
#include <time.h>

namespace {

const char *CLOCK_PATH = "/clock.json";

} // namespace

bool DeviceClock::begin() {
    m_epochBase = 0;
    m_millisBase = millis();
    m_timeTrusted = false;

    if (!LittleFS.begin()) {
        m_lastError = "LittleFS gagal dibuka";
        return false;
    }

    if (!loadPersisted()) {
        Serial.println(F("[clock] belum ada jam tersimpan, menunggu server/NTP"));
        return true;
    }

    Serial.printf("[clock] jam dipulihkan: %lu\n", (unsigned long)m_epochBase);
    return true;
}

bool DeviceClock::loadPersisted() {
    if (!LittleFS.exists(CLOCK_PATH)) {
        return false;
    }

    File f = LittleFS.open(CLOCK_PATH, "r");
    if (!f) {
        return false;
    }

    JsonDocument doc;
    DeserializationError err = deserializeJson(doc, f);
    f.close();

    if (err) {
        m_lastError = "clock.json rusak";
        return false;
    }

    uint32_t saved = doc["epoch"] | 0u;
    if (saved < MIN_VALID_EPOCH || saved > MAX_VALID_EPOCH) {
        return false;
    }

    // Waktu acuan adalah saat file dibaca, bukan saat file ditulis: perangkat
    // pasti sudah mati di antaranya, jadi jam ini sengaja sedikit tertinggal.
    m_epochBase = saved;
    m_millisBase = millis();

    return true;
}

void DeviceClock::beginNtp() {
    // SNTP berjalan di background; time() baru terisi beberapa detik kemudian.
    configTime(0, 0, "pool.ntp.org", "time.google.com");
    m_ntpStarted = true;
    m_lastNtpAttemptMs = 0;

    Serial.println(F("[clock] SNTP dimulai"));
}

void DeviceClock::tick() {
    if (!m_ntpStarted || m_timeTrusted) {
        return;
    }

    if (millis() - m_lastNtpAttemptMs < NTP_RETRY_MS) {
        return;
    }

    m_lastNtpAttemptMs = millis();

    time_t now = time(nullptr);
    if (now < (time_t)MIN_VALID_EPOCH) {
        return;
    }

    if (setEpoch((uint32_t)now)) {
        Serial.printf("[clock] SNTP tersinkron: %lu\n", (unsigned long)m_epochBase);
    }
}

uint32_t DeviceClock::epoch() const {
    if (m_epochBase == 0) {
        return 0;
    }

    // Selisih unsigned tetap benar saat millis() wrap setelah ~49 hari.
    return m_epochBase + (millis() - m_millisBase) / 1000UL;
}

bool DeviceClock::setEpoch(uint32_t value) {
    if (value < MIN_VALID_EPOCH || value > MAX_VALID_EPOCH) {
        m_lastError = "epoch di luar rentang wajar";
        return false;
    }

    m_epochBase = value;
    m_millisBase = millis();
    m_timeTrusted = true;

    // Simpan segera pada kesempatan berikutnya: kalau listrik mati lagi,
    // perangkat tidak kembali tanpa jam.
    m_lastPersistMs = 0;

    return true;
}

void DeviceClock::persistIfDue() {
    if (m_epochBase == 0) {
        return;
    }

    if (m_lastPersistMs != 0 && millis() - m_lastPersistMs < PERSIST_INTERVAL_MS) {
        return;
    }

    persist();
}

bool DeviceClock::persist() {
    JsonDocument doc;
    doc["epoch"] = epoch();

    File f = LittleFS.open(CLOCK_PATH, "w");
    if (!f) {
        m_lastError = "gagal menulis clock.json";
        return false;
    }

    size_t written = serializeJson(doc, f);
    f.close();

    if (written == 0) {
        m_lastError = "tulis clock.json kosong";
        return false;
    }

    m_lastPersistMs = millis();
    return true;
}
