<?php

namespace App\Console\Commands;

use App\Events\OrderVoided;
use App\Models\AuditLog;
use App\Models\Transaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Membatalkan order `awaiting_rfid` yang sudah lewat batas waktu tap.
 * Dijalankan tiap menit oleh scheduler (lihat routes/console.php).
 */
class VoidExpiredOrders extends Command
{
    protected $signature = 'orders:void-expired {--dry-run : Hanya tampilkan, jangan ubah data}';

    protected $description = 'Batalkan order menunggu tap RFID yang sudah kedaluwarsa';

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
        $query->with('transactionItems')->chunkById(100, function ($orders) use (&$voided) {
            foreach ($orders as $order) {
                $changed = DB::transaction(function () use ($order) {
                    // lockForUpdate + re-check: mencegah membatalkan order yang
                    // mungkin saja baru saja selesai diproses tap RFID.
                    //
                    // Hanya Status_AWAITING_RFID yang lolos. `canBePaidByTap()`
                    // tidak boleh dipakai di sini: ia juga bernilai false untuk
                    // order yang KEDALUWARSA, padahal justru itu yang kita batalkan.
                    $fresh = Transaction::where('id', $order->id)->lockForUpdate()->first();

                    if (! $fresh || ! $fresh->isAwaitingRfid()) {
                        return false;
                    }

                    $fresh->forceFill([
                        'status' => Transaction::STATUS_VOIDED,
                        'voided_at' => now(),
                        'error_code' => 'RFID_TIMEOUT',
                        'error_message' => 'Tidak ada tap RFID dalam batas waktu.',
                    ])->save();

                    // Ticket 09: kasir yang sedang menunggu tap harus diberi
                    // tahu seketika, bukan tetap menggantung sampai waktu habis.
                    OrderVoided::dispatch($fresh, 'kedaluwarsa');

                    AuditLog::create([
                        'console_id' => $fresh->console_id,
                        'user_id' => $fresh->user_id,
                        'action' => 'transaction.voided_timeout',
                        'metadata' => [
                            'transaction_id' => $fresh->id,
                            'total_cents' => $fresh->total_cents,
                            'expires_at' => $fresh->expires_at?->toIso8601String(),
                        ],
                    ]);

                    return true;
                });

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