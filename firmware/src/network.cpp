#include "network.h"

#include <ArduinoJson.h>
#include <ESP8266HTTPClient.h>
#include <ESP8266WiFi.h>
#include <WiFiClient.h>

#include "config.h"

namespace {

constexpr uint32_t WIFI_TIMEOUT_MS = 15000;
constexpr uint32_t HTTP_TIMEOUT_MS = 4000;
constexpr uint8_t FAIL_THRESHOLD = 3;

/**
 * Sync boleh lebih lama dari tap biasa: server harus menyelesaikan beberapa
 * transaksi DB sekaligus (dan mengirim Realtime dalam satu batch).
 */
constexpr uint32_t SYNC_TIMEOUT_MS = 6000;

void parseCommands(JsonVariantConst data, std::vector<DeviceCommand> &out) {
    out.clear();

    JsonArrayConst list = data["queue_commands"].as<JsonArrayConst>();

    for (JsonVariantConst item : list) {
        DeviceCommand command;
        command.id = item["id"] | "";
        command.command = item["command"] | "";

        if (command.id.length() > 0) {
            out.push_back(command);
        }
    }
}

} // namespace

bool Network::begin() {
    const DeviceConfig &cfg = Config::get();

    if (cfg.wifiSsid[0] == '\0') {
        m_offlineReason = OfflineReason::NoWifi;
        Serial.println(F("[net] belum ada SSID WiFi"));
        return false;
    }

    Serial.printf("[net] connect ke %s\n", cfg.wifiSsid);

    WiFi.mode(WIFI_STA);
    WiFi.hostname(cfg.deviceId);
    WiFi.begin(cfg.wifiSsid, cfg.wifiPassword);

    const uint32_t started = millis();

    while (WiFi.status() != WL_CONNECTED && (millis() - started) < WIFI_TIMEOUT_MS) {
        // Tetap memberi kesempatan ke loop utama menulis sesuatu? Tidak: ini
        // satu-satunya-blocking yang diizinkan dan hanya di tahap boot.
        delay(100);
    }

    if (WiFi.status() != WL_CONNECTED) {
        m_online = false;
        m_offlineReason = OfflineReason::NoWifi;
        Serial.println(F("[net] gagal connect WiFi"));
        return false;
    }

    Serial.print(F("[net] IP: "));
    Serial.println(WiFi.localIP());

    m_online = true;
    m_offlineReason = OfflineReason::None;
    m_lastOkAt = millis();
    m_failStreak = 0;

    return true;
}

bool Network::tick() {
    bool connected = (WiFi.status() == WL_CONNECTED);

    if (connected != m_online) {
        if (!connected) {
            Serial.println(F("[net] WiFi terputus"));
            m_online = false;
            m_offlineReason = OfflineReason::NoWifi;
        } else {
            Serial.println(F("[net] WiFi kembali tersambung"));
            m_online = true;
            m_offlineReason = OfflineReason::None;
            m_failStreak = 0;
            m_lastOkAt = millis();
        }
    }

    return m_online;
}

int Network::wifiRssi() {
    return m_online ? WiFi.RSSI() : 0;
}

void Network::startConfigPortal() {
    m_online = false;
    m_offlineReason = OfflineReason::NoWifi;

    Serial.println(F("[net] membuka captive portal (192.168.4.1)"));

    WiFi.mode(WIFI_AP_STA);
    WiFi.softAP("Kasir-Setup", nullptr);

    // Beri kasir kesempatan mengisi SSID lewat portal selama 120 detik.
    for (int i = 0; i < 120 && WiFi.status() != WL_CONNECTED; i++) {
        delay(1000);
    }
}

int Network::httpWithTimeout(const String &method, const String &path,
                             const String &body, String &out, uint32_t timeoutMs) {
    if (!m_online) {
        m_offlineReason = OfflineReason::NoWifi;
        return -1;
    }

    if (timeoutMs == 0) {
        timeoutMs = HTTP_TIMEOUT_MS;
    }

    const DeviceConfig &cfg = Config::get();
    if (cfg.apiKey[0] == '\0') {
        Serial.println(F("[net] API key belum diisi"));
        return -1;
    }

    String url = Config::serverUrl() + path;

    // Core ESP8266 baru: begin(WiFiClient, url). Variadic begin(url) sudah
    // ditandai obsolete.
    WiFiClient client;
    HTTPClient http;
    http.setTimeout(timeoutMs);

    if (!http.begin(client, url)) {
        Serial.printf("[net] gagal membuat koneksi ke %s\n", url.c_str());
        return -1;
    }

    http.addHeader("X-Device-ID", cfg.deviceId);
    http.addHeader("X-API-Key", cfg.apiKey);
    http.addHeader("Accept", "application/json");

    int status = -1;

    if (method == "GET") {
        http.setReuse(false);
        status = http.GET();
    } else if (method == "POST") {
        http.addHeader("Content-Type", "application/json");
        status = http.POST(body);
    }

    if (status > 0) {
        out = http.getString();
    } else {
        m_offlineReason = OfflineReason::ServerUnreachable;
        Serial.printf("[net] %s %s gagal (%d)\n", method.c_str(), path.c_str(), status);
    }

    http.end();
    return status;
}

bool Network::sendHeartbeat(uint32_t *serverEpoch, std::vector<DeviceCommand> *commands,
                            size_t queuePending, size_t queueFailed) {
    JsonDocument doc;
    doc["ip_address"] = WiFi.localIP().toString();
    doc["wifi_rssi"] = WiFi.RSSI();
    doc["firmware_version"] = FIRMWARE_VERSION;
    doc["status"] = "online";
    doc["queue_pending"] = (unsigned int)queuePending;
    doc["queue_failed"] = (unsigned int)queueFailed;

    String body;
    serializeJson(doc, body);

    String response;
    int status = httpWithTimeout("POST", "/api/console/heartbeat", body, response);

    if (status < 0) {
        m_failStreak++;
        if (m_failStreak >= FAIL_THRESHOLD) {
            m_online = false;
            m_offlineReason = OfflineReason::ServerUnreachable;
        }
        return false;
    }

    if (status == 200 || status == 201) {
        m_lastHeartbeatAt = millis();
        m_failStreak = 0;
        m_lastOkAt = millis();

        JsonDocument parsed;
        if (!deserializeJson(parsed, response)) {
            JsonVariantConst data = parsed["data"].as<JsonVariantConst>();

            if (serverEpoch != nullptr) {
                *serverEpoch = data["server_epoch"] | 0u;
            }

            if (commands != nullptr) {
                parseCommands(data, *commands);
            }
        }

        return true;
    }

    // 401 = perangkat/api key tidak dikenali: masalah konfigurasi, bukan jaringan.
    if (status == 401) {
        Serial.println(F("[net] 401: device_id atau api_key tidak dikenali"));
        m_failStreak++;
    }

    return false;
}

TapResponse Network::rfidTap(const String &rfidUid, const String &orderId,
                              const String &idempotencyKey) {
    TapResponse res;

    JsonDocument doc;
    doc["rfid_uid"] = rfidUid;
    doc["order_id"] = orderId;
    doc["idempotency_key"] = idempotencyKey;

    String body;
    serializeJson(doc, body);

    String response;
    int status = httpWithTimeout("POST", "/api/console/rfid-tap", body, response);

    if (status < 0) {
        res.message = "Server tidak bisa dihubungi";
        return res;
    }

    JsonDocument parsed;
    DeserializationError err = deserializeJson(parsed, response);

    if (err) {
        res.message = String("Respons tidak valid: ") + err.c_str();
        return res;
    }

    res.ok = parsed["success"] | false;
    res.duplicate = parsed["duplicate"] | false;
    res.errorCode = parsed["error_code"] | "";
    res.message = parsed["message"] | "";

    if (res.ok) {
        // ArduinoJson 7 tidak mendukung .as<JsonDocument>() dari sebuah
        // variant; akses bertingkat langsung lebih hemat RAM.
        JsonVariantConst data = parsed["data"].as<JsonVariantConst>();
        res.receiptNo = data["receipt_no"] | "";
        res.buyerName = data["buyer_name"] | "";
        res.newBalanceCents = data["new_balance_cents"] | 0L;
        res.totalCents = data["total_cents"] | 0L;
    }

    // 422 berarti server menjawab dengan alasan yang jelas (saldo kurang, order
    // sudah diproses) — itu bukan kegagalan jaringan, jadi tetap "direspons".
    if (status == 422 || status == 404) {
        res.ok = false;
    }

    if (status >= 200 && status < 300) {
        m_failStreak = 0;
        m_lastOkAt = millis();
    }

    return res;
}

SyncResult Network::syncTaps(const std::vector<QueueRecord> &records,
                             size_t queuePending, size_t queueFailed) {
    SyncResult res;

    JsonDocument doc;
    doc["queue_pending"] = (unsigned int)queuePending;
    doc["queue_failed"] = (unsigned int)queueFailed;

    JsonArray taps = doc["taps"].to<JsonArray>();

    for (const auto &r : records) {
        JsonObject item = taps.add<JsonObject>();
        item["idempotency_key"] = r.idempotencyKey;
        item["rfid_uid"] = r.rfidUid;
        item["order_id"] = r.orderId;
        item["created_at"] = r.createdAt;
        item["retry_count"] = r.retryCount;
        item["status"] = (r.status == RecordStatus::Voided) ? "voided" : "pending";
    }

    String body;
    serializeJson(doc, body);

    // Bebaskan dokumen sebelum panggilan HTTP: heap ESP8266 sempit.
    doc.clear();

    String response;
    int httpStatus = httpWithTimeout("POST", "/api/console/sync", body, response,
                                     SYNC_TIMEOUT_MS);

    if (httpStatus < 0) {
        m_failStreak++;
        res.error = "Server tidak bisa dihubungi";
        return res;
    }

    if (httpStatus < 200 || httpStatus >= 300) {
        res.error = String("HTTP ") + httpStatus;
        return res;
    }

    res.transportOk = true;
    m_failStreak = 0;
    m_lastOkAt = millis();

    JsonDocument parsed;
    if (deserializeJson(parsed, response)) {
        res.error = "Respons tidak valid";
        return res;
    }

    res.ok = parsed["success"] | false;

    JsonVariantConst data = parsed["data"].as<JsonVariantConst>();
    res.serverEpoch = data["server_epoch"] | 0u;

    parseCommands(data, res.commands);

    JsonArrayConst results = data["results"].as<JsonArrayConst>();

    for (JsonVariantConst item : results) {
        SyncResultItem out;
        out.idempotencyKey = item["idempotency_key"] | "";
        out.status = item["status"] | "";
        out.message = item["message"] | "";
        out.receiptNo = item["receipt_no"] | "";
        out.buyerName = item["buyer_name"] | "";
        out.totalCents = item["total_cents"] | 0L;
        out.newBalanceCents = item["new_balance_cents"] | 0L;

        res.items.push_back(out);
    }

    return res;
}

bool Network::ackCommand(const String &id, bool ok, const String &message) {
    JsonDocument doc;
    JsonArray commands = doc["commands"].to<JsonArray>();
    JsonObject item = commands.add<JsonObject>();
    item["id"] = id;
    item["status"] = ok ? "ok" : "failed";

    if (message.length() > 0) {
        item["message"] = message;
    }

    String body;
    serializeJson(doc, body);

    String response;
    int status = httpWithTimeout("POST", "/api/console/commands/ack", body, response);

    if (status < 200 || status >= 300) {
        Serial.printf("[net] ack perintah gagal (%d)\n", status);
        return false;
    }

    return true;
}

bool Network::fetchBalance(const String &rfidUid, long &balanceCents, String &name) {
    String response;
    int status = httpWithTimeout("GET", "/api/console/balance/" + rfidUid, "", response);

    if (status != 200) {
        return false;
    }

    JsonDocument doc;
    if (deserializeJson(doc, response)) {
        return false;
    }

    if (!(doc["success"] | false)) {
        return false;
    }

    balanceCents = doc["data"]["balance_cents"] | 0L;
    name = doc["data"]["name"] | "";

    return true;
}

OrderStatus Network::fetchOrderStatus(const String &orderId) {
    OrderStatus st;

    String response;
    int status = httpWithTimeout("GET", "/api/console/pending-order", "", response);

    if (status != 200) {
        return st;
    }

    JsonDocument doc;
    if (deserializeJson(doc, response)) {
        return st;
    }

    if (doc["data"].isNull()) {
        // Tidak ada order yang menunggu tap.
        return st;
    }

    JsonVariantConst data = doc["data"].as<JsonVariantConst>();

    st.found = true;
    st.orderId = data["order_id"] | "";
    st.status = data["status"] | "";
    st.totalCents = data["total_cents"] | 0L;

    // Backend mengirim expires_at ISO-8601. ESP8266 tidak punya parser tanggal
    // lengkap yang murah, jadi cukup tandai masih berlaku atau tidak;
    // batas waktunya sudah difilter server (order basi tidak dikirim lagi).
    st.expiresAtMs = data["expires_at"].isNull() ? 0 : 1;

    return st;
}