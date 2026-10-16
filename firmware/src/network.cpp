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
                             const String &body, String &out) {
    if (!m_online) {
        m_offlineReason = OfflineReason::NoWifi;
        return -1;
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
    http.setTimeout(HTTP_TIMEOUT_MS);

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

bool Network::sendHeartbeat() {
    JsonDocument doc;
    doc["ip_address"] = WiFi.localIP().toString();
    doc["wifi_rssi"] = WiFi.RSSI();
    doc["firmware_version"] = FIRMWARE_VERSION;
    doc["status"] = "online";

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