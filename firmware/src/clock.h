#pragma once

#include <Arduino.h>

/**
 * Jam dinding perangkat.
 *
 * Kenapa tidak cukup `millis()`: antrean tap harus bertahan setelah listrik
 * mati, sedangkan `millis()` kembali ke 0 setiap boot. Tap dari sesi sebelumnya
 * akan terlihat "berumur 49 hari" (underflow unsigned) lalu langsung
 * dibatalkan — persis kebalikan dari yang diinginkan.
 *
 * Urutan sumber waktu:
 *   1. `server_epoch` dari respons heartbeat/sync (paling murah, selalu ada
 *      selama perangkat online)
 *   2. NTP setelah WiFi tersambung
 *   3. epoch terakhir yang disimpan di LittleFS
 *
 * Epoch tersimpan selalu *lebih tua* daripada waktu sebenarnya (perangkat
 * sempat mati), sehingga auto-void bisa tertunda tetapi tidak pernah
 * membatalkan tap yang masih baru. Begitu server/NTP memberi waktu benar,
 * umur tap langsung terkoreksi.
 */
class DeviceClock
{
public:
    /// 2023-11-14: batas bawah yang masuk akal untuk jam yang belum disetel.
    static constexpr uint32_t MIN_VALID_EPOCH = 1700000000UL;

    /// 2039-09-27: batas atas supaya nilai rusak tidak merusak perhitungan umur.
    static constexpr uint32_t MAX_VALID_EPOCH = 2200000000UL;

    /// Tulis jam ke LittleFS tiap 5 menit, bukan tiap detik (umur flash).
    static constexpr uint32_t PERSIST_INTERVAL_MS = 5UL * 60UL * 1000UL;

    /// Jeda antar percobaan membaca hasil SNTP.
    static constexpr uint32_t NTP_RETRY_MS = 30000UL;

    /// Muat epoch terakhir dari LittleFS. LittleFS harus sudah di-mount.
    bool begin();

    /// Mulai SNTP (tidak memblokir); hasilnya dibaca di tick().
    void beginNtp();

    /// Cek hasil SNTP. Panggil dari loop().
    void tick();

    /// true kalau ada jam yang bisa dipakai.
    bool synced() const { return epoch() > 0; }

    /// Epoch detik, atau 0 kalau jam belum pernah diketahui.
    uint32_t epoch() const;

    /// Setel jam dari server/NTP. Nilai di luar rentang wajar ditolak.
    bool setEpoch(uint32_t value);

    /// Simpan epoch ke LittleFS bila sudah 5 menit sejak simpan terakhir.
    void persistIfDue();

    const String &lastError() const { return m_lastError; }

private:
    bool loadPersisted();
    bool persist();

    uint32_t m_epochBase = 0;
    uint32_t m_millisBase = 0;
    uint32_t m_lastPersistMs = 0;
    uint32_t m_lastNtpAttemptMs = 0;
    bool m_ntpStarted = false;
    bool m_timeTrusted = false;
    String m_lastError;
};
