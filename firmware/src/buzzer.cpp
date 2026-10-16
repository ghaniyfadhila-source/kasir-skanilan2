#include "buzzer.h"

namespace {

struct Step {
    bool on;
    uint16_t ms;
};

struct PatternDef {
    Step steps[4];
    uint8_t count;
};

// Nada dalam Hz. Durasi dalam ms.
constexpr PatternDef PATTERNS[] = {
    {{}, 0},                                                       // None
    {{{true, 80}, {false, 80}, {true, 80}, {false, 0}}, 3},        // Success: beep beep
    {{{true, 400}, {false, 0}}, 2},                                // Error: beep panjang
    {{{true, 100}, {false, 0}}, 2},                                // Warning
    {{{true, 60}, {false, 60}, {true, 90}, {false, 60}}, 4},        // Offline chirp
};

} // namespace

void Buzzer::begin(uint8_t pin) {
    m_pin = pin;
    pinMode(m_pin, OUTPUT);
    noTone(m_pin);
}

void Buzzer::play(Pattern p) {
    // Jangan potong pola yang sedang berjalan dengan pola lain.
    if (m_pattern == p) {
        return;
    }

    m_pattern = p;
    m_step = 0;

    if (p == Pattern::None) {
        noTone(m_pin);
        return;
    }

    m_total = PATTERNS[(uint8_t)p].count;
    startNextTone();
}

void Buzzer::startNextTone() {
    if (m_step >= m_total) {
        m_pattern = Pattern::None;
        m_step = 0;
        noTone(m_pin);
        return;
    }

    const Step &s = PATTERNS[(uint8_t)m_pattern].steps[m_step];
    m_phase = s.on ? 1 : 0;
    m_phaseStartedAt = millis();

    if (s.on) {
        tone(m_pin, 2000);
    } else {
        noTone(m_pin);
    }

    m_step++;
}

void Buzzer::tick() {
    if (m_pattern == Pattern::None) {
        return;
    }

    uint16_t duration = PATTERNS[(uint8_t)m_pattern].steps[m_step - 1].ms;

    if (millis() - m_phaseStartedAt >= duration) {
        startNextTone();
    }
}