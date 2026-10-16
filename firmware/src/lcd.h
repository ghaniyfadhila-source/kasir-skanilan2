#pragma once

#include <Arduino.h>

/**
 * Driver LCD I2C 1602/2004 (PCF8574 backpack).
 *
 * Baris 16 karakter ditulis sebagai satu string penuh, lalu seluruh frame
 * dikirim sekaligus. Ini menghindari kedipan/flicker yang terjadi kalau
 * Home + print per kolom seperti cara naive.
 */
class Lcd {
public:
    void begin(uint8_t addr = 0x27, uint8_t cols = 16, uint8_t rows = 2);

    /// Tulis seluruh isi layar dalam satu transaksi tampil.
    void frame(const String &line1, const String &line2 = "");

    void setCursor(uint8_t col, uint8_t row);

    void print(const String &text);
    void clear();
    void backlight(bool on);

    bool ready() const { return m_ready; }

    /// Jumlah karakter per baris.
    uint8_t cols() const { return m_cols; }
    uint8_t rows() const { return m_rows; }

private:
    void send(uint8_t data, bool isData);
    void expanderWrite(uint8_t data);

    uint8_t m_addr;
    uint8_t m_cols;
    uint8_t m_rows;
    bool m_backlight = true;
    bool m_ready = false;
    uint8_t m_addrCounter = 0;
};

/// Format angka ke gaya Indonesia: 2500000 -> "25.000"
String rupiah(long sen);