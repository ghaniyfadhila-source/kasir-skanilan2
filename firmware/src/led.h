#pragma once

#include <Arduino.h>

/**
 * LED status: hijau = siap, merah = bermasalah.
 * Tidak memakai hardware timer; hanya menyalakan/mematikan pin.
 */
class StatusLed {
public:
    void begin(uint8_t greenPin, uint8_t redPin);

    void setOnline(bool online);
    void setBusy(bool busy);

    void tick();

private:
    void apply();

    uint8_t m_green;
    uint8_t m_red;
    bool m_online = false;
    bool m_busy = false;
    uint32_t m_lastBlink = 0;
};