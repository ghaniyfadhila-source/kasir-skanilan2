<?php

namespace App\Events;

use App\Models\Transaction;
use App\Support\Rupiah;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Transaksi berhasil settle setelah pembeli tap kartu RFID.
 *
 * Dipicu dari RfidTapService, bukan dari controller, supaya semua jalan yang
 * bisa menghasilkan pembayaran - REST, Replay idempoten, atau settlement
 * otomatis - tetap menghasilkan satu event yang sama.
 */
class TransactionCompleted
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Transaction $transaction,
    ) {}

    /**
     * Data siap kirim ke browser.
     *
     * Dipisah ke sini supaya bentuk payload tidak tersebar di listener dan di
     * controller sekaligus. Field yang dikirim hanya yang benar-benar dipakai
     * UI: tidak ada id pembeli, saldo, atau data kartu RFID.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $transaction = $this->transaction;
        $transaction->loadMissing(['buyer', 'console']);

        return [
            'transaction_id' => $transaction->id,
            'receipt_no' => $transaction->receipt_no,
            'status' => $transaction->status,
            'total_cents' => $transaction->total_cents,
            'total_formatted' => Rupiah::format($transaction->total_cents),
            'console_id' => $transaction->console_id,
            'device_id' => $transaction->console?->device_id,
            'buyer_name' => $transaction->buyer?->name,

            // Saldo pembeli ikut dikirim supaya halaman struk bisa langsung
            // menampilkannya tanpa permintaan tambahan.
            'new_balance_cents' => $transaction->buyer?->balance_cents,
            'new_balance_formatted' => Rupiah::formatNullable($transaction->buyer?->balance_cents),
            'completed_at' => $transaction->completed_at?->toIso8601String(),
        ];
    }
}