#include <Arduino.h>
#include <LittleFS.h>

#include "buzzer.h"
#include "config.h"
#include "lcd.h"
#include "led.h"
#include "network.h"
#include "queue.h"
#include "rfid.h"
#include "state.h"

/**
 * Firmware kasir RFID — Kasir Skanilan 2.
 *
 * Alur utama:
 *   boot -> wifi -> tampil "SIAP"
 *   kasir buat order di Cashier Web UI
 *   perangkat polling order menunggu -> LCD "TAP RFID | Rp 15.000"
 *   pembeli tap kartu -> POST /api/console/rfid-tap
 *   sukses: LCD struk + saldo, beep 2x
 *   gagal (offline): tap disimpan di LittleFS, dikirim ulang saat online
 *   tap > 5 menit di antrean -> auto void
 *
 * Semua pekerjaan berat tidak memakai delay() yang panjang supaya loop
 * tetap responsif terhadap tap kartu.
 */

// --- Pinout NodeMCU v3 -------------------------------------------------------
constexpr uint8_t PIN_LCD_SDA = 4;   // D2
constexpr uint8_t PIN_LCD_SCL = 5;   // D1
constexpr uint8_t PIN_LCD_ADDR = 0x27;

constexpr uint8_t PIN_RFID_SS = 15;  // D8
constexpr uint8_t PIN_RFID_RST = 16; // D0
constexpr uint8_t PIN_BUZZER = 5;     // D1 (dipakai bersama SCL LCD)
constexpr uint8_t PIN_LED_GREEN = 14; // D5
constexpr uint8_t PIN_LED_RED = 2;    // D4

// --- Timing ------------------------------------------------------------------
constexpr uint32_t TICK_MS = 100;           // tick loop utama
constexpr uint32_t HEARTBEAT_INTERVAL_MS = 30000;
constexpr uint32_t POLL_ORDER_INTERVAL_MS = 1500;
constexpr uint32_t STATUS_HOLD_MS = 4000;   // berapa lama status akhir ditahan
constexpr uint32_t RETRY_INTERVAL_MS = 5000; // jeda kirim ulang antrean

// --- Objek global ------------------------------------------------------------
Lcd lcd;
Buzzer buzzer;
StatusLed led;
Rfid rfid;
Network net;
TapQueue queue;

AppState g_state = AppState::Boot;
OfflineReason g_offlineReason = OfflineReason::None;

// Order yang sedang menunggu tap.
String g_pendingOrderId;
long g_pendingTotalCents = 0;

// Status hasil terakhir (ditahan sebentar di LCD).
AppState g_holdState = AppState::Ready;
String g_holdLine1;
String g_holdLine2;
uint32_t g_holdUntil = 0;

// Anti-spam tap.
uint32_t g_lastTapAt = 0;
constexpr uint32_t TAP_DEBOUNCE_MS = 3000;

// --- Fungsi bantu ------------------------------------------------------------

/// Tahan status di layar selama beberapa detik lalu kembali ke SIAP.
void holdStatus(AppState s, const String &l1, const String &l2) {
    g_holdState = s;
    g_holdLine1 = l1;
    g_holdLine2 = l2;
    g_holdUntil = millis() + STATUS_HOLD_MS;

    buzzer.play(s == AppState::Success   ? Buzzer::Pattern::Success
                : s == AppState::InvalidCard ? Buzzer::Pattern::Warning
                : s == AppState::Insufficient ? Buzzer::Pattern::Error
                                               : Buzzer::Pattern::None);
}

/// Tentukan baris LCD berdasarkan state saat ini.
void renderScreen() {
    if (millis() < g_holdUntil) {
        lcd.frame(g_holdLine1, g_holdLine2);
        return;
    }

    switch (g_state) {
        case AppState::Boot:
            lcd.frame("Kasir Skanilan", "Memuat...");
            break;

        case AppState::ConnectingWifi:
            lcd.frame("Menghubungkan", "WiFi...");
            break;

        case AppState::ConfigAp:
            lcd.frame("Mode Setup", "192.168.4.1");
            break;

        case AppState::Offline: {
            String reason = offlineReasonLabel(g_offlineReason);
            String l2 = reason.length() ? reason : "OFFLINE MODE";
            if (queue.pendingCount() > 0) {
                l2 += " | " + String(queue.pendingCount()) + " nunggu";
            }
            lcd.frame("OFFLINE MODE", l2);
            break;
        }

        case AppState::AwaitingTap:
            lcd.frame("TAP RFID", "Rp " + rupiah(g_pendingTotalCents));
            break;

        case AppState::Success:
            lcd.frame(g_holdLine1, g_holdLine2);
            break;

        case AppState::OrderVoided:
            lcd.frame("DIBATALKAN", "Order dibatalkan");
            break;

        default:
            if (g_pendingOrderId.length()) {
                lcd.frame("TAP RFID", "Rp " + rupiah(g_pendingTotalCents));
            } else {
                lcd.frame("SIAP", Config::get().deviceId);
            }
            break;
    }
}

/// Buat idempotency key yang unik per tap.
String makeIdempotencyKey() {
    char buf[48];
    snprintf(buf, sizeof(buf), "%s-%lu-%04X", Config::get().deviceId,
             (unsigned long)micros(), (unsigned)(ESP.random() & 0xFFFF));
    return String(buf);
}

/// Proses satu tap kartu RFID.
void handleTap(const String &uid) {
    if (millis() - g_lastTapAt < TAP_DEBOUNCE_MS) {
        Serial.println(F("[main] tap diabaikan (debounce)"));
        return;
    }
    g_lastTapAt = millis();

    if (g_pendingOrderId.length() == 0) {
        holdStatus(AppState::Error, "TIDAK ADA ORDER", "Buat order dulu");
        return;
    }

    const String idem = makeIdempotencyKey();

    if (!net.online()) {
        // Offline: simpan ke antrean, kirim nanti.
        QueueRecord r;
        r.idempotencyKey = idem;
        r.rfidUid = uid;
        r.orderId = g_pendingOrderId;
        r.createdAt = millis();
        r.retryCount = 0;
        r.status = RecordStatus::Pending;

        QueueResult res = queue.enqueue(r);

        if (res == QueueResult::Full) {
            holdStatus(AppState::Error, "ANTRIAN PENUH", "Hubungi admin");
            buzzer.play(Buzzer::Pattern::Error);
            return;
        }

        g_pendingOrderId = "";
        g_pendingTotalCents = 0;
        g_state = AppState::Offline;

        holdStatus(AppState::Offline, "OFFLINE", "Tersimpan, kirim nanti");
        return;
    }

    led.setBusy(true);
    TapResponse res = net.rfidTap(uid, g_pendingOrderId, idem);
    led.setBusy(false);

    if (res.ok) {
        g_pendingOrderId = "";
        g_pendingTotalCents = 0;
        g_state = AppState::Ready;

        holdStatus(AppState::Success,
                   "SUKSES " + rupiah(res.totalCents),
                   res.buyerName + " | Sisa " + rupiah(res.newBalanceCents));

        Serial.printf("[main] tap sukses, struk %s\n", res.receiptNo.c_str());
        return;
    }

    // Server menjawab dengan alasan yang jelas: perlakukan sebagai error aplikasi,
    // bukan kegagalan jaringan, jadi jangan masukkan ke antrean.
    if (!res.errorCode.isEmpty()) {
        if (res.errorCode == "insufficient_balance") {
            holdStatus(AppState::Insufficient, "SALDO KURANG", res.buyerName.isEmpty() ? "" : res.buyerName);
        } else if (res.errorCode == "card_invalid") {
            holdStatus(AppState::InvalidCard, "KARTU TIDAK VALID", "");
        } else if (res.errorCode == "order_not_tappable") {
            g_pendingOrderId = "";
            g_pendingTotalCents = 0;
            g_state = AppState::Ready;
            holdStatus(AppState::OrderVoided, "ORDER HABIS", res.message);
        } else {
            holdStatus(AppState::Error, "GAGAL", res.errorCode);
        }
        return;
    }

    // Tanpa errorCode = kemungkinan besar jaringan putus.
    QueueRecord r;
    r.idempotencyKey = idem;
    r.rfidUid = uid;
    r.orderId = g_pendingOrderId;
    r.createdAt = millis();
    r.retryCount = 0;
    r.status = RecordStatus::Pending;

    if (queue.enqueue(r) == QueueResult::Ok) {
        g_offlineReason = OfflineReason::ServerUnreachable;
        g_state = AppState::Offline;
        holdStatus(AppState::Offline, "OFFLINE", "Tersimpan, kirim nanti");
    } else {
        holdStatus(AppState::Error, "ANTRIAN PENUH", "Hubungi admin");
        buzzer.play(Buzzer::Pattern::Error);
    }
}

/// Kirim tap yang tersimpan di antrean.
void flushQueue() {
    if (!net.online()) {
        return;
    }

    const QueueRecord *rec = queue.oldestPending();
    if (!rec) {
        return;
    }

    // Salin dulu: remove() akan mengubah vektor dan membatalkan pointer.
    QueueRecord copy = *rec;

    led.setBusy(true);
    TapResponse res = net.rfidTap(copy.rfidUid, copy.orderId, copy.idempotencyKey);
    led.setBusy(false);

    if (res.ok || !res.errorCode.isEmpty()) {
        // Sukses, duplikat, atau ditolak dengan alasan yang jelas: tidak perlu
        // dicoba lagi.
        queue.remove(copy.idempotencyKey);
        Serial.printf("[main] antrian: %s selesai (%s)\n",
                      copy.idempotencyKey.c_str(), res.receiptNo.c_str());
        return;
    }

    queue.bumpRetry(copy.idempotencyKey);
}

/// Polling order yang menunggu tap.
void pollPendingOrder() {
    if (!net.online()) {
        return;
    }

    OrderStatus st = net.fetchOrderStatus(g_pendingOrderId);

    if (st.found) {
        bool changed = (st.orderId != g_pendingOrderId);
        g_pendingOrderId = st.orderId;
        g_pendingTotalCents = st.totalCents;

        if (changed && g_state != AppState::Offline) {
            g_state = AppState::AwaitingTap;
        }
    } else if (g_pendingOrderId.length()) {
        // Order hilang dari sisi server: sudah dibayar atau batal.
        g_pendingOrderId = "";
        g_pendingTotalCents = 0;
        g_state = AppState::Ready;
    }
}

// --- Setup & loop -----------------------------------------------------------

void setup() {
    Serial.begin(115200);
    delay(50);

    Serial.println();
    Serial.println(F("================================="));
    Serial.println(F(" Kasir Skanilan 2 — Firmware Kasir"));
    Serial.printf( " Versi: %s\n", FIRMWARE_VERSION);
    Serial.println(F("================================="));

    pinMode(PIN_LED_RED, OUTPUT);
    digitalWrite(PIN_LED_RED, HIGH);

    lcd.begin(PIN_LCD_ADDR, 16, 2);
    lcd.frame("Kasir Skanilan", "Starting...");
    led.begin(PIN_LED_GREEN, PIN_LED_RED);
    led.setOnline(false);

    if (!LittleFS.begin()) {
        Serial.println(F("[main] FATAL: LittleFS gagal"));
        lcd.frame("FATAL", "LittleFS gagal");
        while (true) {
            delay(1000);
        }
    }

    DeviceConfig cfg;
    Config::load(cfg);

    Serial.printf("[main] device_id: %s\n", cfg.deviceId);
    Serial.printf("[main] server   : %s\n", cfg.serverUrl);
    Serial.printf("[main] api_key  : %s\n", cfg.apiKey[0] ? "TERISI" : "KOSONG (isi dulu)");

    buzzer.begin(PIN_BUZZER);
    rfid.begin(PIN_RFID_SS, PIN_RFID_RST);
    queue.begin();

    g_state = AppState::ConnectingWifi;
    lcd.frame("Menghubungkan", "WiFi...");

    if (!net.begin()) {
        g_state = AppState::ConfigAp;
        g_offlineReason = OfflineReason::NoWifi;
        net.startConfigPortal();
    }

    if (net.online()) {
        net.sendHeartbeat();
        led.setOnline(true);
        g_state = AppState::Ready;
    } else {
        g_state = AppState::Offline;
    }

    Serial.println(F("[main] siap"));
}

void loop() {
    static uint32_t lastTick = 0;
    static uint32_t lastHeartbeat = 0;
    static uint32_t lastPoll = 0;
    static uint32_t lastFlush = 0;
    static uint32_t lastVoidScan = 0;

    const uint32_t now = millis();

    // --- Tick utama 100 ms ------------------------------------------------
    if (now - lastTick >= TICK_MS) {
        lastTick = now;

        buzzer.tick();
        led.tick();
        led.setOnline(net.online());
        renderScreen();
    }

    net.tick();

    // --- RFID ------------------------------------------------------------
    String uid;
    bool cardPresent = false;
    rfid.poll(uid, cardPresent);

    if (cardPresent && !rfid.isDuplicate(uid)) {
        Serial.printf("[main] tap kartu %s\n", uid.c_str());
        handleTap(uid);
    }

    // --- Heartbeat -------------------------------------------------------
    if (now - lastHeartbeat >= HEARTBEAT_INTERVAL_MS) {
        lastHeartbeat = now;
        if (net.online()) {
            bool ok = net.sendHeartbeat();
            led.setOnline(ok);
            if (!ok && !g_pendingOrderId.length()) {
                g_state = AppState::Offline;
            }
        }
    }

    // --- Polling order ---------------------------------------------------
    if (now - lastPoll >= POLL_ORDER_INTERVAL_MS) {
        lastPoll = now;
        pollPendingOrder();
    }

    // --- Kirim antrean ---------------------------------------------------
    if (now - lastFlush >= RETRY_INTERVAL_MS) {
        lastFlush = now;
        flushQueue();
    }

    // --- Auto-void antrean basi ------------------------------------------
    if (now - lastVoidScan >= 60000) {
        lastVoidScan = now;
        uint16_t voided = queue.autoVoidExpired();
        if (voided > 0) {
            holdStatus(AppState::OrderVoided,
                       String(voided) + " TRANSAKSI BATAL",
                       "Terlalu lama offline");
        }
    }

    delay(1); // yield ke WiFi stack tanpa memblokir loop
}