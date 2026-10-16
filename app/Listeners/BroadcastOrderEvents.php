<?php

namespace App\Listeners;

use App\Events\OrderPlaced;
use App\Events\OrderVoided;
use App\Events\TransactionCompleted;
use App\Services\Realtime\RealtimePublisher;
use App\Services\Realtime\RealtimeTopic;

/**
 * Menerjemahkan peristiwa order menjadi Realtime untuk kasir dan admin.
 *
 * Dipisahkan dari service yang membangkitkan peristiwa supaya
 * {@see \App\Services\RfidTapService} tidak perlu tahu apa pun soal Supabase.
 * Itu penting karena RfidTapService adalah tempat paling kritis di sistem ini:
 * ia memegang `lockForUpdate()` saat deduplikasi tap. Menambah pemanggilan HTTP
 * ke dalamnya berisiko menggeser perilaku itu.
 */
class BroadcastOrderEvents
{
    public function __construct(private readonly RealtimePublisher $publisher) {}

    /**
     * Pembayaran berhasil.
     *
     * Dikirim ke channel kasir (supaya halamannya pindah ke struk) dan ke
     * dashboard admin (supaya aktivitas terbaru langsung muncul).
     */
    public function handleCompleted(TransactionCompleted $event): void
    {
        $payload = $event->payload();

        $deviceId = $payload['device_id'] ?? null;

        if (is_string($deviceId) && $deviceId !== '') {
            $this->publisher->broadcast(
                RealtimeTopic::cashier($deviceId),
                'completed',
                $payload
            );
        }

        $this->publisher->broadcast(
            RealtimeTopic::adminDashboard(),
            'transaction.created',
            $payload
        );
    }

    /**
     * Order dibatalkan, baik manual maupun karena kedaluwarsa.
     */
    public function handleVoided(OrderVoided $event): void
    {
        $payload = $event->payload();
        $deviceId = $payload['device_id'] ?? null;

        if (is_string($deviceId) && $deviceId !== '') {
            $this->publisher->broadcast(
                RealtimeTopic::cashier($deviceId),
                'void',
                $payload
            );
        }

        $this->publisher->broadcast(
            RealtimeTopic::adminDashboard(),
            'order.voided',
            $payload
        );
    }

    /**
     * Order baru menunggu tap.
     */
    public function handlePlaced(OrderPlaced $event): void
    {
        $this->publisher->broadcast(
            RealtimeTopic::adminDashboard(),
            'order.placed',
            $event->payload()
        );
    }
}