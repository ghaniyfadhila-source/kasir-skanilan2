<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use App\Services\OrderVoidService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Membatalkan order `awaiting_rfid` yang sudah lewat batas waktu tap.
 * Dijalankan tiap menit oleh scheduler (lihat routes/console.php).
 */
class VoidExpiredOrders extends Command
{
    protected $signature = 'orders:void-expired {--dry-run : Hanya tampilkan, jangan ubah data}';

    protected $description = 'Batalkan order menunggu tap RFID yang sudah kedaluwarsa';

    public function __construct(private readonly OrderVoidService $voids)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $query = Transaction::query()
            ->expired()
            ->where(fn ($q) => $q->whereNull('voided_at'));

        if ($this->option('dry-run')) {
            $count = (clone $query)->count();
            $this->info("Mode dry-run: $count order akan dibatalkan.");

            return self::SUCCESS;
        }

        $voided = 0;

        // Diproses per-chunk supaya tidak menahan lock panjang di database.
        $query->select('id')->chunkById(100, function ($orders) use (&$voided) {
            foreach ($orders as $order) {
                // `voidIfAwaiting()` mengunci baris dan mengecek ulang status:
                // order yang baru saja dibayar tap RFID tidak ikut dibatalkan.
                $changed = $this->voids->voidIfAwaiting(
                    orderId: $order->id,
                    errorCode: 'RFID_TIMEOUT',
                    errorMessage: 'Tidak ada tap RFID dalam batas waktu.',
                    auditAction: 'transaction.voided_timeout',
                    reason: 'kedaluwarsa',
                );

                if ($changed) {
                    $voided++;
                }
            }
        });

        Log::channel('audit')->info('Expired orders voided', ['count' => $voided]);

        $this->info("$voided order dibatalkan.");

        return self::SUCCESS;
    }
}
