#include <Arduino.h>
#include <LittleFS.h>

#include "buzzer.h"
#include "clock.h"
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
 *   tap > 5 menit di antrean -> auto void + dilaporkan ke server
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
constexpr uint32_t TICK_MS = 100;                  // tick loop utama
constexpr uint32_t HEARTBEAT_INTERVAL_MS = 30000;
constexpr uint32_t POLL_ORDER_INTERVAL_MS = 1500;
constexpr uint32_t STATUS_HOLD_MS = 4000;          // berapa lama status akhir ditahan
constexpr uint32_t RETRY_INTERVAL_MS = 5000;       // jeda kirim ulang antrean
constexpr uint32_t RECONNECT_FLUSH_DELAY_MS = 2000; // beri WiFi waktu sebelum sync
constexpr uint32_t FLUSH_GAP_MS = 500;             // jeda antar batch saat banyak tersisa

/// Jumlah tap per request /api/console/sync (batas server 50).
constexpr size_t SYNC_BATCH_SIZE = 10;

// --- Objek global ------------------------------------------------------------
Lcd lcd;
Buzzer buzzer;
StatusLed led;
Rfid rfid;
Network net;
TapQueue queue;
DeviceClock deviceClock;

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

// Penanda sesi. Record antrean menyimpan ini supaya tap dari boot sebelumnya
// bisa dikenali saat jam dinding belum tersedia.
uint32_t g_bootId = 1;

// Penjadwal.
uint32_t g_lastTickAt = 0;
uint32_t g_lastHeartbeatAt = 0;
uint32_t g_lastPollAt = 0;
uint32_t g_lastVoidScanAt = 0;
uint32_t g_nextFlushAt = 0;
bool g_wasOnline = false;
bool g_flushing = false;

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
            String l2 = offlineReasonLabel(g_offlineReason);
            if (queue.pendingCount() > 0) {
                l2 = String((unsigned)queue.pendingCount()) + " tap nunggu";
            } else if (queue.failedCount() > 0) {
                l2 = String((unsigned)queue.failedCount()) + " gagal";
            } else if (l2.length() == 0) {
                l2 = "OFFLINE MODE";
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
///
/// Memakai epoch kalau sudah ada supaya kunci tetap unik antar-reboot.
String makeIdempotencyKey() {
    char buf[48];

    unsigned long stamp = deviceClock.synced()
                              ? (unsigned long)deviceClock.epoch()
                              : (unsigned long)(millis() / 1000UL);

    snprintf(buf, sizeof(buf), "%s-%lu-%04X", Config::get().deviceId, stamp,
             (unsigned)(ESP.random() & 0xFFFF));

    return String(buf);
}

/// Susun record antrean baru, lengkap dengan penanda waktu dan boot.
QueueRecord makeQueueRecord(const String &uid, const String &orderId) {
    QueueRecord r;
    r.idempotencyKey = makeIdempotencyKey();
    r.rfidUid = uid;
    r.orderId = orderId;
    r.createdAt = deviceClock.epoch();
    r.createdAtMs = millis();
    r.bootId = g_bootId;
    r.retryCount = 0;
    r.status = RecordStatus::Pending;
    return r;
}

/// Jalankan perintah admin yang ikut pada heartbeat/sync.
void handleCommands(const std::vector<DeviceCommand> &commands) {
    for (const auto &command : commands) {
        if (command.command == "queue.clear") {
            uint16_t removed = queue.clearAll();
            net.ackCommand(command.id, true);

            holdStatus(AppState::Offline, "ANTRIAN DIKOSONGKAN",
                       String((unsigned)removed) + " tap dibuang");

            Serial.printf("[main] queue.clear: %u record dibuang\n", (unsigned)removed);
            continue;
        }

        net.ackCommand(command.id, false, "perintah tidak dikenal");
        Serial.printf("[main] perintah tidak dikenal: %s\n", command.command.c_str());
    }
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

    if (!net.online()) {
        // Offline: simpan ke antrean, kirim nanti.
        QueueRecord r = makeQueueRecord(uid, g_pendingOrderId);
        const String key = r.idempotencyKey;

        QueueResult res = queue.enqueue(r);

        if (res == QueueResult::Full) {
            g_offlineReason = OfflineReason::QueueFull;
            holdStatus(AppState::Error, "QUEUE FULL", "Hubungi admin");
            buzzer.play(Buzzer::Pattern::Error);
            return;
        }

        if (res != QueueResult::Ok) {
            holdStatus(AppState::Error, "GAGAL SIMPAN", queue.lastError());
            return;
        }

        g_pendingOrderId = "";
        g_pendingTotalCents = 0;
        g_state = AppState::Offline;

        holdStatus(AppState::Offline, "OFFLINE - QUEUED", "Kirim otomatis");

        Serial.printf("[main] offline tap disimpan: %s\n", key.c_str());
        return;
    }

    led.setBusy(true);
    const String idempotencyKey = makeIdempotencyKey();
    TapResponse res = net.rfidTap(uid, g_pendingOrderId, idempotencyKey);
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
    //
    // Kunci idempotensi WAJIB sama dengan yang sudah dikirim. Kalau respons
    // sukses hilang di jalan, server sudah menyimpan kunci itu; mengirim ulang
    // dengan kunci yang sama membuat server menjawab `duplicate` (lengkap
    // dengan struk), bukan memproses transaksi kedua.
    QueueRecord r = makeQueueRecord(uid, g_pendingOrderId);
    r.idempotencyKey = idempotencyKey;

    if (queue.enqueue(r) == QueueResult::Ok) {
        g_pendingOrderId = "";
        g_pendingTotalCents = 0;
        g_offlineReason = OfflineReason::ServerUnreachable;
        g_state = AppState::Offline;
        holdStatus(AppState::Offline, "OFFLINE - QUEUED", "Kirim otomatis");
    } else {
        holdStatus(AppState::Error, "ANTRIAN PENUH", "Hubungi admin");
        buzzer.play(Buzzer::Pattern::Error);
    }
}

/**
 * Kirim antrean ke server, beberapa record per request.
 *
 * Aturan pembuangan record:
 *   - success / duplicate / void / insufficient_* / card_invalid / discard
 *     -> hapus, mengulang tidak akan mengubah hasil
 *   - error -> retry_count++; setelah 3x record berhenti dicoba otomatis
 *   - tidak ada respons HTTP -> JANGAN naikkan retry_count; ini gangguan
 *     jaringan, bukan penolakan server
 */
void flushQueue() {
    if (!net.online() || g_flushing) {
        return;
    }

    std::vector<QueueRecord> batch;
    const size_t count = queue.collectBatch(SYNC_BATCH_SIZE, batch);

    if (count == 0) {
        return;
    }

    g_flushing = true;
    led.setBusy(true);

    // Tampil seketika: panggilan HTTP di bawah memblokir loop.
    lcd.frame("SYNCING...", String((unsigned)count) + " tap");

    SyncResult res = net.syncTaps(batch, queue.pendingCount(), queue.failedCount());

    led.setBusy(false);
    g_flushing = false;

    if (!res.transportOk) {
        g_offlineReason = OfflineReason::ServerUnreachable;
        g_nextFlushAt = millis() + RETRY_INTERVAL_MS;

        Serial.printf("[main] sync gagal: %s\n", res.error.c_str());
        return;
    }

    if (res.serverEpoch > 0) {
        deviceClock.setEpoch(res.serverEpoch);
    }

    uint16_t sent = 0;
    uint16_t voided = 0;
    uint16_t rejected = 0;
    uint16_t retried = 0;

    for (const auto &item : res.items) {
        if (item.idempotencyKey.length() == 0) {
            continue;
        }

        if (item.status == "success" || item.status == "duplicate") {
            queue.remove(item.idempotencyKey);
            sent++;
        } else if (item.status == "void") {
            queue.remove(item.idempotencyKey);
            voided++;
        } else if (item.status == "insufficient_balance" ||
                   item.status == "insufficient_stock" ||
                   item.status == "card_invalid" ||
                   item.status == "discard") {
            queue.remove(item.idempotencyKey);
            rejected++;
        } else {
            queue.bumpRetry(item.idempotencyKey);
            retried++;
        }
    }

    // Perintah admin bisa ikut pada respons sync.
    handleCommands(res.commands);

    Serial.printf("[main] sync: %u sukses, %u void, %u ditolak, %u retry\n",
                  (unsigned)sent, (unsigned)voided, (unsigned)rejected,
                  (unsigned)retried);

    if (sent > 0) {
        holdStatus(AppState::Success, "SYNC SELESAI", String((unsigned)sent) + " tap terkirim");
    } else if (voided > 0) {
        holdStatus(AppState::OrderVoided, "TXN VOIDED", String((unsigned)voided) + " tap dibatalkan");
    } else if (rejected > 0) {
        holdStatus(AppState::Error, "TAP DITOLAK", String((unsigned)rejected) + " tap");
    } else if (retried > 0) {
        holdStatus(AppState::Error, "SYNC GAGAL", String((unsigned)retried) + " tap dicoba lagi");
    }

    // Masih ada sisa? Lanjutkan cepat, jangan tunggu interval retry penuh.
    if (queue.pendingCount() + queue.voidedCount() > 0) {
        g_nextFlushAt = millis() + FLUSH_GAP_MS;
    } else {
        g_nextFlushAt = millis() + RETRY_INTERVAL_MS;
    }
}

/// Polling order yang menunggu tap.
void pollPendingOrder() {
    if (!net.online()) {
        return;
    }

    OrderStatus st = net.fetchOrderStatus(g_pendingOrderId);

    if (st.found) {
        g_pendingOrderId = st.orderId;
        g_pendingTotalCents = st.totalCents;

        // Idempoten: kalau order masih menunggu, layar memang harus TAP RFID.
        // Status hasil yang sedang ditahan tetap menang lewat g_holdUntil.
        g_state = AppState::AwaitingTap;
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

    g_bootId = ESP.random();
    if (g_bootId == 0) {
        g_bootId = 1;
    }

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

    deviceClock.begin();
    queue.begin();

    g_state = AppState::ConnectingWifi;
    lcd.frame("Menghubungkan", "WiFi...");

    if (!net.begin()) {
        g_state = AppState::ConfigAp;
        g_offlineReason = OfflineReason::NoWifi;
        net.startConfigPortal();
    }

    if (net.online()) {
        deviceClock.beginNtp();

        uint32_t epoch = 0;
        std::vector<DeviceCommand> commands;
        bool ok = net.sendHeartbeat(&epoch, &commands, queue.pendingCount(),
                                    queue.failedCount());

        if (ok) {
            if (epoch > 0) {
                deviceClock.setEpoch(epoch);
            }
            handleCommands(commands);
        }

        g_lastHeartbeatAt = millis();
        led.setOnline(true);
        g_state = AppState::Ready;
    } else {
        g_state = AppState::Offline;
    }

    Serial.printf("[main] siap (boot %08lX)\n", (unsigned long)g_bootId);
}

void loop() {
    const uint32_t now = millis();

    // --- Tick utama 100 ms ------------------------------------------------
    if (now - g_lastTickAt >= TICK_MS) {
        g_lastTickAt = now;

        buzzer.tick();
        led.tick();
        led.setOnline(net.online());
        renderScreen();

        deviceClock.tick();
        deviceClock.persistIfDue();
    }

    net.tick();

    // --- Deteksi kembali online -------------------------------------------
    const bool online = net.online();

    if (online && !g_wasOnline) {
        // Beri WiFi/SNTP waktu sebentar sebelum mengirim antrean, tetapi ambil
        // perintah admin lebih dulu: `queue.clear` harus sempat diproses
        // sebelum tap lama dikirim.
        g_nextFlushAt = now + RECONNECT_FLUSH_DELAY_MS;
        g_lastHeartbeatAt = now - HEARTBEAT_INTERVAL_MS;

        Serial.println(F("[main] online: heartbeat + sync dijadwalkan"));
    }

    // Layar sempat menampilkan OFFLINE MODE; setelah koneksi pulih jangan
    // biarkan status itu tertinggal sampai ada order baru.
    if (online && g_state == AppState::Offline) {
        g_state = g_pendingOrderId.length() ? AppState::AwaitingTap : AppState::Ready;
    }

    g_wasOnline = online;

    // --- RFID ------------------------------------------------------------
    String uid;
    bool cardPresent = false;
    rfid.poll(uid, cardPresent);

    if (cardPresent && !rfid.isDuplicate(uid)) {
        Serial.printf("[main] tap kartu %s\n", uid.c_str());
        handleTap(uid);
    }

    // --- Heartbeat -------------------------------------------------------
    if (now - g_lastHeartbeatAt >= HEARTBEAT_INTERVAL_MS) {
        g_lastHeartbeatAt = now;

        if (net.online()) {
            uint32_t epoch = 0;
            std::vector<DeviceCommand> commands;

            bool ok = net.sendHeartbeat(&epoch, &commands, queue.pendingCount(),
                                        queue.failedCount());

            led.setOnline(ok);

            if (ok) {
                if (epoch > 0) {
                    deviceClock.setEpoch(epoch);
                }
                handleCommands(commands);
            } else if (!g_pendingOrderId.length()) {
                g_state = AppState::Offline;
            }
        }
    }

    // --- Polling order ---------------------------------------------------
    if (now - g_lastPollAt >= POLL_ORDER_INTERVAL_MS) {
        g_lastPollAt = now;
        pollPendingOrder();
    }

    // --- Kirim antrean ---------------------------------------------------
    if (online && now >= g_nextFlushAt) {
        flushQueue();
    }

    // --- Auto-void antrean basi ------------------------------------------
    if (now - g_lastVoidScanAt >= 60000) {
        g_lastVoidScanAt = now;

        String firstKey;
        uint16_t voided = queue.autoVoidExpired(deviceClock.epoch(), millis(), g_bootId, &firstKey);

        if (voided > 0) {
            String shortKey = firstKey.length() > 16
                                  ? firstKey.substring(firstKey.length() - 16)
                                  : firstKey;

            holdStatus(AppState::OrderVoided, "TXN VOIDED: " + String((unsigned)voided), shortKey);

            // Segera laporkan ke server supaya order di sana ikut ditutup.
            g_nextFlushAt = millis();
        }
    }

    delay(1); // yield ke WiFi stack tanpa memblokir loop
}
