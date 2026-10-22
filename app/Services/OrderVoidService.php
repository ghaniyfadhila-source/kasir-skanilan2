<?php

namespace App\Services;

use App\Events\OrderVoided;
use App\Models\AuditLog;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

/**
 * Membatalkan order yang masih menunggu tap RFID.
 *
 * Dipakai dua sumber yang berbeda:
 *   - `orders:void-expired` (backend, saat batas waktu 60 detik lewat)
 *   - `POST /api/console/sync` (perangkat melaporkan tap yang sudah terlalu
 *     lama tersimpan di antrean offline)
 *
 * Keduanya harus melakukan hal yang sama persis, jadi logikanya dipusatkan di
 * sini. Lock + cek ulang status mencegah order yang baru saja dibayar ikut
 * dibatalkan.
 */
class OrderVoidService
{
    /**
     * @return Transaction|null null bila order tidak ada atau sudah tidak menunggu tap
     */
    public function voidIfAwaiting(
        string $orderId,
        string $errorCode,
        string $errorMessage,
        string $auditAction,
        string $reason,
        ?string $consoleId = null,
        ?string $voidedBy = null,
    ): ?Transaction {
        return DB::transaction(function () use (
            $orderId,
            $errorCode,
            $errorMessage,
            $auditAction,
            $reason,
            $consoleId,
            $voidedBy
        ) {
            $order = Transaction::query()
                ->where('id', $orderId)
                ->when($consoleId !== null, fn ($q) => $q->where('console_id', $consoleId))
                ->lockForUpdate()
                ->first();

            // Status harus dicek ulang setelah lock: bisa saja tap yang sah
            // baru saja menyelesaikan order ini.
            if (! $order || ! $order->isAwaitingRfid()) {
                return null;
            }

            $order->forceFill([
                'status' => Transaction::STATUS_VOIDED,
                'voided_at' => now(),
                'voided_by' => $voidedBy,
                'error_code' => $errorCode,
                'error_message' => $errorMessage,
            ])->save();

            AuditLog::create([
                'console_id' => $order->console_id,
                'user_id' => $order->user_id,
                'action' => $auditAction,
                'metadata' => [
                    'transaction_id' => $order->id,
                    'total_cents' => $order->total_cents,
                    'expires_at' => $order->expires_at?->toIso8601String(),
                ],
            ]);

            // Event menembak HTTP ke Supabase Realtime. Dispatch setelah commit
            // supaya lock baris tidak ditahan selama panggilan jaringan.
            DB::afterCommit(fn () => OrderVoided::dispatch($order, $reason));

            return $order;
        });
    }
}
