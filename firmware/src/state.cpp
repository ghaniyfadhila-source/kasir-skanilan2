#include "state.h"

const char *stateLabel(AppState s) {
    switch (s) {
        case AppState::Boot:           return "BOOT";
        case AppState::ConnectingWifi: return "MENYAMBUNG WIFI";
        case AppState::ConfigAp:       return "SETUP KONFIGURASI";
        case AppState::Ready:          return "SIAP";
        case AppState::AwaitingTap:    return "TAP RFID";
        case AppState::Success:        return "SUKSES";
        case AppState::Insufficient:   return "SALDO KURANG";
        case AppState::InvalidCard:    return "KARTU TIDAK VALID";
        case AppState::Offline:        return "OFFLINE MODE";
        case AppState::OrderVoided:    return "DIBATALKAN";
        case AppState::Error:          return "ERROR";
    }
    return "?";
}

const char *offlineReasonLabel(OfflineReason r) {
    switch (r) {
        case OfflineReason::None:               return "";
        case OfflineReason::NoWifi:             return "NO WIFI";
        case OfflineReason::ServerUnreachable:  return "SERVER OFF";
        case OfflineReason::QueueFull:          return "ANTRIAN PENUH";
    }
    return "";
}