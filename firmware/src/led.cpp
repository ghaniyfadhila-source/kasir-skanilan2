#include "led.h"

void StatusLed::begin(uint8_t greenPin, uint8_t redPin) {
    m_green = greenPin;
    m_red = redPin;

    pinMode(m_green, OUTPUT);
    pinMode(m_red, OUTPUT);

    apply();
}

void StatusLed::setOnline(bool online) {
    m_online = online;
    apply();
}

void StatusLed::setBusy(bool busy) {
    m_busy = busy;
    apply();
}

void StatusLed::tick() {
    if (!m_busy) {
        return;
    }

    // Kedip saat sedang bekerja supaya kasir tahu perangkat tidak beku.
    if (millis() - m_lastBlink >= 250) {
        m_lastBlink = millis();
        apply();
    }
}

void StatusLed::apply() {
    bool greenOn = m_online;
    bool redOn = !m_online;

    if (m_busy) {
        const bool blink = (millis() % 500) < 250;
        greenOn = m_online && blink;
        redOn = !m_online && blink;
    }

    digitalWrite(m_green, greenOn ? HIGH : LOW);
    digitalWrite(m_red, redOn ? HIGH : LOW);
}