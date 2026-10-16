<?php

namespace App\Listeners;

use App\Events\ConsoleStatusChanged;
use App\Events\MenuItemChanged;
use App\Models\Console;
use App\Services\Realtime\RealtimePublisher;
use App\Services\Realtime\RealtimeTopic;

/**
 * Menerjemahkan perubahan master data menjadi Realtime.
 *
 * Perubahan menu dan status konsol tidak terlalu sering terjadi, tapi dampaknya
 * besar: harga yang salah di layar kasir berarti transaksi salah tagih.
 */
class BroadcastCatalogEvents
{
    public function __construct(private readonly RealtimePublisher $publisher) {}

    /**
     * Harga atau ketersediaan menu berubah.
     *
     * Dikirim ke semua konsol yang aktif, bukan hanya satu, karena admin mengubah
     * menu secara global dan tidak ada konsep "menu milik konsol ini".
     */
    public function handleMenuChanged(MenuItemChanged $event): void
    {
        $topics = Console::query()
            ->where('is_active', true)
            ->pluck('device_id')
            ->map(fn (string $deviceId): string => RealtimeTopic::cashier($deviceId))
            ->all();

        $topics[] = RealtimeTopic::adminDashboard();

        $this->publisher->broadcastToMany($topics, 'menu.updated', $event->payload());
    }

    /**
     * Status konsol berubah karena heartbeat atau perubahan manual.
     */
    public function handleConsoleChanged(ConsoleStatusChanged $event): void
    {
        $this->publisher->broadcast(
            RealtimeTopic::adminDashboard(),
            'console.status',
            $event->payload()
        );
    }
}