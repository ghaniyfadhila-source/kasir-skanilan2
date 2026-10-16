#pragma once

#include <Arduino.h>
#include <FS.h>

/**
 * Konfigurasi runtime perangkat.
 *
 * Nilai disimpan di LittleFS (`/config.json`) supaya device_id, WiFi, dan
 * API key bisa diisi dari captive portal tanpa compile ulang. Semua nilai
 * punya default supaya perangkat tetap bisa boot tanpa file konfigurasi.
 */
struct DeviceConfig {
    char deviceId[24];       // "ESP-AABBCCDDEEFF"
    char apiKey[72];         // dari `php artisan console:credentials`
    char serverUrl[96];      // "http://192.168.1.10:8000"
    char wifiSsid[33];
    char wifiPassword[65];
    char cashierPassword[65]; // opsional: bila diisi, dipakai untuk login Cashier Web UI
    uint32_t orderPollMs;    // jeda polling order
};

namespace Config {

// Device ID diturunkan dari MAC address: ESP- + 6 byte terakhir uppercase.
String deriveDeviceId();

void defaults();

bool load(DeviceConfig &out);
bool save(const DeviceConfig &cfg);

// Akses cepat ke nilai yang sudah dimuat.
const DeviceConfig &get();

// Simpan hanya sebagian field (dipakai captive portal).
bool updateField(const String &key, const String &value);

String serverUrl();

} // namespace Config