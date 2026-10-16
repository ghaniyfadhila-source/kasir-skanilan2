#pragma once

#include <Arduino.h>

/**
 * Buzzer pasif untuk pola nada pendek (tidak blocking).
 * Pola dijalankan lewat state machine internal supaya loop utama tidak pernah
 * berhenti (`delay()` di loop RFID akan membuat tap terlewat).
 */
class Buzzer {
public:
    void begin(uint8_t pin);

    enum class Pattern : uint8_t {
        None,
        Success,     // 2 beep pendek
        Error,       // 1 beep panjang
        Warning,     // 1 beep pendek
        Offline,     // chirp 3 naik-turun
    };

    void play(Pattern p);

    /// Dipanggil tiap loop untuk melanjutkan nada yang sedang berjalan.
    void tick();

    bool busy() const { return m_pattern != Pattern::None; }

private:
    void startNextTone();

    uint8_t m_pin;
    uint8_t m_step = 0;
    uint8_t m_phase = 0;      // 0 = senyap, 1 = berbunyi
    uint8_t m_total = 0;
    uint32_t m_phaseStartedAt = 0;
    Pattern m_pattern = Pattern::None;
};