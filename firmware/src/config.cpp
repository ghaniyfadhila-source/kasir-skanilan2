#include "config.h"

#include <ArduinoJson.h>
#include <ESP8266WiFi.h>
#include <LittleFS.h>

namespace {

DeviceConfig g_cfg;
bool g_loaded = false;

const char *CONFIG_PATH = "/config.json";

void copyBounded(char *dst, size_t cap, const String &src) {
    strlcpy(dst, src.c_str(), cap);
}

} // namespace

namespace Config {

String deriveDeviceId() {
    uint8_t mac[6];
    WiFi.macAddress(mac);

    char buf[24];
    snprintf(buf, sizeof(buf), "ESP-%02X%02X%02X%02X%02X%02X",
             mac[0], mac[1], mac[2], mac[3], mac[4], mac[5]);

    return String(buf);
}

void defaults() {
    DeviceConfig d;
    copyBounded(d.deviceId, sizeof(d.deviceId), deriveDeviceId());
    copyBounded(d.apiKey, sizeof(d.apiKey), "");
    copyBounded(d.serverUrl, sizeof(d.serverUrl), "http://192.168.1.10:8000");
    copyBounded(d.wifiSsid, sizeof(d.wifiSsid), "");
    copyBounded(d.wifiPassword, sizeof(d.wifiPassword), "");
    copyBounded(d.cashierPassword, sizeof(d.cashierPassword), "");
    d.orderPollMs = 2000;

    g_cfg = d;
    g_loaded = true;
}

bool load(DeviceConfig &out) {
    if (!LittleFS.begin()) {
        Serial.println(F("[config] LittleFS gagal dibuka, pakai default"));
        defaults();
        out = g_cfg;
        return false;
    }

    if (!LittleFS.exists(CONFIG_PATH)) {
        Serial.println(F("[config] config.json belum ada, pakai default"));
        defaults();
        out = g_cfg;
        return false;
    }

    File f = LittleFS.open(CONFIG_PATH, "r");
    if (!f) {
        defaults();
        out = g_cfg;
        return false;
    }

    JsonDocument doc;
    DeserializationError err = deserializeJson(doc, f);
    f.close();

    if (err) {
        Serial.printf("[config] JSON rusak (%s), pakai default\n", err.c_str());
        defaults();
        out = g_cfg;
        return false;
    }

    DeviceConfig d;
    copyBounded(d.deviceId, sizeof(d.deviceId), doc["device_id"] | deriveDeviceId());
    copyBounded(d.apiKey, sizeof(d.apiKey), doc["api_key"] | "");
    copyBounded(d.serverUrl, sizeof(d.serverUrl), doc["server_url"] | "http://192.168.1.10:8000");
    copyBounded(d.wifiSsid, sizeof(d.wifiSsid), doc["wifi_ssid"] | "");
    copyBounded(d.wifiPassword, sizeof(d.wifiPassword), doc["wifi_password"] | "");
    copyBounded(d.cashierPassword, sizeof(d.cashierPassword), doc["cashier_password"] | "");
    d.orderPollMs = doc["order_poll_ms"] | 2000u;

    g_cfg = d;
    g_loaded = true;
    out = d;

    Serial.printf("[config] termuat: %s -> %s\n", d.deviceId, d.serverUrl);
    return true;
}

bool save(const DeviceConfig &cfg) {
    if (!LittleFS.begin()) {
        return false;
    }

    JsonDocument doc;
    doc["device_id"] = cfg.deviceId;
    doc["api_key"] = cfg.apiKey;
    doc["server_url"] = cfg.serverUrl;
    doc["wifi_ssid"] = cfg.wifiSsid;
    doc["wifi_password"] = cfg.wifiPassword;
    doc["cashier_password"] = cfg.cashierPassword;
    doc["order_poll_ms"] = cfg.orderPollMs;

    File f = LittleFS.open(CONFIG_PATH, "w");
    if (!f) {
        Serial.println(F("[config] gagal menulis config.json"));
        return false;
    }

    serializeJson(doc, f);
    f.close();

    g_cfg = cfg;
    g_loaded = true;

    Serial.println(F("[config] disimpan"));
    return true;
}

const DeviceConfig &get() {
    if (!g_loaded) {
        defaults();
    }
    return g_cfg;
}

bool updateField(const String &key, const String &value) {
    DeviceConfig cfg = get();
    bool changed = true;

    if (key == "device_id") {
        copyBounded(cfg.deviceId, sizeof(cfg.deviceId), value);
    } else if (key == "api_key") {
        copyBounded(cfg.apiKey, sizeof(cfg.apiKey), value);
    } else if (key == "server_url") {
        copyBounded(cfg.serverUrl, sizeof(cfg.serverUrl), value);
    } else if (key == "wifi_ssid") {
        copyBounded(cfg.wifiSsid, sizeof(cfg.wifiSsid), value);
    } else if (key == "wifi_password") {
        copyBounded(cfg.wifiPassword, sizeof(cfg.wifiPassword), value);
    } else {
        changed = false;
    }

    return changed ? save(cfg) : false;
}

String serverUrl() {
    String url = String(get().serverUrl);
    while (url.length() && url.endsWith("/")) {
        url.remove(url.length() - 1);
    }
    return url;
}

} // namespace Config