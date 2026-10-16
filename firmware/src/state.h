#pragma once

#include <Arduino.h>

/**
 * Status yang ditampilkan ke kasir lewat LCD.
 * Dipakai juga untuk menentukan nada buzzer.
 */
enum class AppState : uint8_t {
    Boot,
    ConnectingWifi,
    ConfigAp,
    Ready,            // "SIAP" — menunggu ada order
    AwaitingTap,      // "TAP RFID | Rp X"
    Success,          // "SUKSES: Rp X, Saldo: Rp Y"
    Insufficient,     // "SALDO KURANG"
    InvalidCard,      // "KARTU TIDAK VALID"
    Offline,          // "OFFLINE MODE"
    OrderVoided,      // "DIBATALKAN"
    Error,            // "ERROR: ..."
};

const char *stateLabel(AppState s);

/// Alasan offline, supaya LCD bisa membedakan "koneksi putus" vs "server menolak".
enum class OfflineReason : uint8_t {
    None,
    NoWifi,
    ServerUnreachable,
    QueueFull,
};

const char *offlineReasonLabel(OfflineReason r);