<?php

namespace App\Events;

use App\Models\Transaction;
use App\Support\Rupiah;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Kasir membuat order dan sedang menunggu tap RFID.
 *
 * Kasir Web UI yang membuat ordernya sendiri sudah tahu jawabannya, tapi
 * Admin Dashboard memakai event ini untuk menampilkan order yang sedang menunggu
 * pembayaran.
 */
class OrderPlaced
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Transaction $order,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $order = $this->order;
        $order->loadMissing('console');

        return [
            'order_id' => $order->id,
            'status' => $order->status,
            'total_cents' => $order->total_cents,
            'total_formatted' => Rupiah::format($order->total_cents),
            'console_id' => $order->console_id,
            'device_id' => $order->console?->device_id,
            'expires_at' => $order->expires_at?->toIso8601String(),
        ];
    }
}