#pragma once

#include <Arduino.h>
#include <MFRC522.h>

/**
 * Pembaca kartu RFID MFRC522.
 *
 * Semua operasi berjalan non-blocking: `poll()` hanya mencoba membaca kartu
 * sekali per panggilan dan langsung kembali. Tidak ada delay() panjang di
 * dalam loop, supaya tap kartu, animasi LCD, dan heartbeat tetap responsif.
 *
 * Catatan library: memakai paket registry `miguelbalboa/MFRC522` 1.4.x yang
 * memakai API bergaya PCD_/PICC_. Kalau ingin pindah ke paket
 * `miguelbalboa/rfid` 4.x, cukup ubah empat pemanggilan di rfid.cpp.
 */
class Rfid {
public:
    void begin(uint8_t ssPin, uint8_t rstPin);

    /**
     * Coba baca kartu.
     * @param uid keluar: UID 8 karakter hex uppercase, atau String kosong
     * @param ok  keluar: true bila ada kartu baru yang terdeteksi
     */
    void poll(String &uid, bool &ok);

    /// Cegah kartu yang sama diproses berulang dalam waktu tertentu.
    bool isDuplicate(const String &uid);

    /// Hilangkan cooldown supaya kartu bisa langsung dipakai lagi.
    void clearCooldown();

private:
    MFRC522 *m_reader = nullptr;
    String m_lastUid;
    uint32_t m_lastSeenAt = 0;

    static constexpr uint32_t COOLDOWN_MS = 2500;
};