<?php

namespace App\Events;

use App\Models\Transaction;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Order dibatalkan sebelum selesai dibayar.
 *
 * Dua sumber: kasir menekan tombol batal, atau order kedaluwarsa dan dibatalkan
 * otomatis oleh scheduler. Keduanya perlu memberi tahu Cashier Web UI supaya
 * halaman "menunggu tap" tidak menggantung sampai waktu tunggu habis.
 */
class OrderVoided
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  string  $reason  alasan singkat yang tampil di UI
     */
    public function __construct(
        public readonly Transaction $order,
        public readonly string $reason = 'dibatalkan',
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
            'reason' => $this->reason,
            'error_code' => $order->error_code,
            'console_id' => $order->console_id,
            'device_id' => $order->console?->device_id,
        ];
    }
}