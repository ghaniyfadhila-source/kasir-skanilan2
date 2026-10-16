#include "rfid.h"

#include <SPI.h>

void Rfid::begin(uint8_t ssPin, uint8_t rstPin) {
    SPI.begin();

    m_reader = new MFRC522(ssPin, rstPin);
    m_reader->PCD_Init();

    // PCD_SetAntennaGain(PCD_GetAntennaGain()) adalah idiom standar library ini
    // untuk mengaktifkan output antena setelah init.
    m_reader->PCD_SetAntennaGain(m_reader->PCD_GetAntennaGain());

    Serial.println(F("[rfid] MFRC522 siap"));
}

void Rfid::poll(String &uid, bool &ok) {
    ok = false;
    uid = "";

    if (!m_reader) {
        return;
    }

    // PICC_IsNewCardPresent() hanya clung-and-catch: tidak memanggil delay(),
    // sehingga aman dipanggil setiap loop.
    if (!m_reader->PICC_IsNewCardPresent()) {
        return;
    }

    if (!m_reader->PICC_ReadCardSerial()) {
        return;
    }

    const uint8_t len = m_reader->uid.size;

    // MIFARE Classic 1K = 4 byte UID -> 8 karakter hex, sesuai kolom
    // rfid_cards.rfid_uid CHAR(8) di backend.
    if (len != 4) {
        Serial.printf("[rfid] UID panjang %u byte, diabaikan (butuh 4)\n",
                      (unsigned)len);
        return;
    }

    char buf[9];
    snprintf(buf, sizeof(buf), "%02X%02X%02X%02X",
             m_reader->uid.uidByte[0], m_reader->uid.uidByte[1],
             m_reader->uid.uidByte[2], m_reader->uid.uidByte[3]);

    uid = String(buf);
    ok = true;
}

bool Rfid::isDuplicate(const String &uid) {
    if (uid.isEmpty()) {
        return true;
    }

    if (uid == m_lastUid && (millis() - m_lastSeenAt) < COOLDOWN_MS) {
        return true;
    }

    m_lastUid = uid;
    m_lastSeenAt = millis();

    return false;
}

void Rfid::clearCooldown() {
    m_lastUid = "";
    m_lastSeenAt = 0;
}