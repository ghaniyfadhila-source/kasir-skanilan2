#include "lcd.h"

#include <Wire.h>

namespace {

// Peta pin PCF8574 keLiquidCrystal_I2C.
constexpr uint8_t PCF8574_BIT_P0 = 0x01; // RS
constexpr uint8_t PCF8574_BIT_P1 = 0x02; // RW
constexpr uint8_t PCF8574_BIT_P2 = 0x04; // EN
constexpr uint8_t PCF8574_BIT_BACKLIGHT = 0x08;

constexpr uint8_t LCD_CMD = 0x00;
constexpr uint8_t LCD_DATA = 0x40;

} // namespace

void Lcd::begin(uint8_t addr, uint8_t cols, uint8_t rows) {
    m_addr = addr;
    m_cols = cols;
    m_rows = rows;

    Wire.begin();
    Wire.beginTransmission(m_addr);
    m_ready = (Wire.endTransmission() == 0);

    if (!m_ready) {
        Serial.println(F("[lcd] backpack I2C tidak menjawab"));
        return;
    }

    // Urutan inisialisasi HD44780 standar.
    expanderWrite(PCF8574_BIT_BACKLIGHT);
    delay(50);

    send(0x30, LCD_CMD); delay(5);
    send(0x30, LCD_CMD); delayMicroseconds(150);
    send(0x30, LCD_CMD); delayMicroseconds(150);

    send(0x38, LCD_CMD); // 8-bit, 2 line, 5x8 font
    delay(5);

    send(0x08, LCD_CMD); // display off
    send(0x01, LCD_CMD); // clear
    delay(5);
    send(0x06, LCD_CMD); // entry mode: increment
    send(0x0C, LCD_CMD); // display on, cursor off

    Serial.printf("[lcd] siap (%ux%u @ 0x%02X)\n", m_cols, m_rows, m_addr);
}

void Lcd::expanderWrite(uint8_t data) {
    Wire.beginTransmission(m_addr);
    Wire.write(data | (m_backlight ? PCF8574_BIT_BACKLIGHT : 0));
    Wire.endTransmission();
}

void Lcd::send(uint8_t data, bool isData) {
    expanderWrite(data);

    // Enable harus pulsa high-low-high supaya byte terkirim.
    expanderWrite(data | PCF8574_BIT_P2);
    delayMicroseconds(1);
    expanderWrite(data & ~PCF8574_BIT_P2);
    delayMicroseconds(50);
}

void Lcd::setCursor(uint8_t col, uint8_t row) {
    // Offset alamat DDRAM tiap baris.
    static const uint8_t offsets[] = {0x00, 0x40, 0x14, 0x54};
    uint8_t off = (row < 4) ? offsets[row] : 0x00;

    send(LCD_CMD | 0x80 | (col + off), false);
}

void Lcd::print(const String &text) {
    for (size_t i = 0; i < text.length(); i++) {
        send(LCD_DATA | (uint8_t)text.charAt(i), true);
    }
}

void Lcd::clear() {
    send(LCD_CMD | 0x01, false);
    delay(5);
}

void Lcd::backlight(bool on) {
    m_backlight = on;
    expanderWrite(0);
}

void Lcd::frame(const String &line1, const String &line2) {
    if (!m_ready) {
        return;
    }

    // Tulis kedua baris sekaligus supaya layar tidak berkedip di tengah teks.
    setCursor(0, 0);
    print(line1);
    for (uint8_t c = line1.length(); c < m_cols; c++) {
        print(" ");
    }

    if (m_rows >= 2) {
        setCursor(0, 1);
        print(line2.substring(0, m_cols));
        for (uint8_t c = (line2.length() < m_cols ? line2.length() : m_cols); c < m_cols; c++) {
            print(" ");
        }
    }

    // Baris 3/4 untuk LCD 2004.
    if (m_rows >= 3) {
        setCursor(0, 2);
        print(line1.substring(m_cols));
    }
    if (m_rows >= 4) {
        setCursor(0, 3);
        print(line2.substring(m_cols));
    }
}

String rupiah(long sen) {
    // Foram("24.700") dari 2470000 sen.
    bool negative = sen < 0;
    unsigned long v = (unsigned long)(negative ? -sen : sen);

    unsigned long whole = v / 100;
    String out;
    out.reserve(16);

    while (whole > 0) {
        unsigned long digit = whole % 10;
        whole /= 10;

        char buf[2] = {(char)('0' + digit), '\0'};
        out = String(buf) + out;
        if (whole > 0 && whole % 3 == 0) {
            out = "." + out;
        }
    }

    if (out.length() == 0) {
        out = "0";
    }

    return (negative ? "-" : "") + out;
}