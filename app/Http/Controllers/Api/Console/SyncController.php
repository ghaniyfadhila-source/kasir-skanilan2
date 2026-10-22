<?php

namespace App\Http\Controllers\Api\Console;

use App\Http\Controllers\Controller;
use App\Models\Console;
use App\Models\Transaction;
use App\Services\ConsoleCommandService;
use App\Services\OrderVoidService;
use App\Services\Realtime\RealtimePublisher;
use App\Services\RfidTapService;
use App\Support\Rupiah;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sinkronisasi antrean tap RFID offline (Ticket 10).
 *
 * Perangkat mengirim beberapa tap sekaligus setelah koneksi pulih. Setiap tap
 * diproses sendiri-sendiri di dalam transaksi DB-nya masing-masing: satu record
 * rusak tidak boleh menggagalkan seluruh batch, dan hasil per record harus
 * dikembalikan supaya perangkat tahu mana yang boleh dibuang dari antrean.
 *
 * Status hasil (kontrak dengan firmware):
 *   success              -> tap berhasil, hapus dari antrean
 *   duplicate            -> tap ini sudah pernah diproses, hapus
 *   void                 -> order sudah tidak bisa ditap, hapus
 *   insufficient_balance -> saldo kurang, hapus (mengulang tidak akan membantu)
 *   insufficient_stock   -> stok habis, hapus
 *   card_invalid         -> kartu tidak dikenal, hapus
 *   discard              -> record tidak valid, hapus
 *   error                -> gagal sementara, coba lagi nanti (retry_count++)
 */
class SyncController extends Controller
{
    /** Batas batch: 50 tap harus selesai jauh di bawah 10 detik. */
    public const MAX_TAPS_PER_REQUEST = 50;

    public function __construct(
        private readonly RfidTapService $taps,
        private readonly OrderVoidService $voids,
        private readonly ConsoleCommandService $commands,
        private readonly RealtimePublisher $realtime,
    ) {
    }

    /**
     * POST /api/console/sync
     *
     * Body: { taps: [...], queue_pending?: int, queue_failed?: int }
     */
    public function sync(Request $request): JsonResponse
    {
        $console = $this->console($request);

        $validated = $request->validate([
            'taps' => ['present', 'array', 'max:'.self::MAX_TAPS_PER_REQUEST],
            'queue_pending' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'queue_failed' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ]);

        $results = [];

        // Semua event Realtime dari batch ini digabung menjadi satu panggilan
        // HTTP. Tanpa ini, 50 tap = 100 panggilan berurutan ke Supabase.
        $this->realtime->beginBatch();

        try {
            foreach ($validated['taps'] as $index => $tap) {
                try {
                    $results[] = $this->processTap($console, $tap, (int) $index);
                } catch (Throwable $e) {
                    // Jaring pengaman terakhir: apa pun yang gagal di luar
                    // `settle()` (mis. laporan void) tidak boleh mematikan
                    // seluruh batch.
                    Log::channel('audit')->error('Console sync item crashed', [
                        'console_id' => $console->id,
                        'index' => $index,
                        'exception' => $e->getMessage(),
                    ]);

                    $results[] = [
                        'index' => (int) $index,
                        'idempotency_key' => is_array($tap) && is_string($tap['idempotency_key'] ?? null)
                            ? $tap['idempotency_key']
                            : null,
                        'status' => 'error',
                        'message' => 'Gagal memproses record; coba lagi.',
                    ];
                }
            }
        } finally {
            $this->realtime->endBatch();
        }

        $this->recordQueueState($console, $validated);

        return response()->json([
            'success' => true,
            'data' => [
                'server_time' => now()->toIso8601String(),
                // Firmware memakai ini untuk menyetel jamnya tanpa NTP, sehingga
                // umur tap di antrean tetap benar setelah perangkat reboot.
                'server_epoch' => now()->getTimestamp(),
                'results' => $results,
                'queue_commands' => $this->commands->outstandingFor($console)
                    ->map(fn ($command) => $command->toDevicePayload())
                    ->values()
                    ->all(),
            ],
        ]);
    }

    /**
     * POST /api/console/commands/ack
     *
     * Body: { commands: [ { id, status: "ok"|"failed", message? } ] }
     */
    public function acknowledgeCommands(Request $request): JsonResponse
    {
        $console = $this->console($request);

        $validated = $request->validate([
            'commands' => ['present', 'array', 'max:20'],
        ]);

        $acknowledged = $this->commands->acknowledge($console, $validated['commands']);

        return response()->json([
            'success' => true,
            'data' => ['acknowledged' => $acknowledged],
        ]);
    }

    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function processTap(Console $console, mixed $tap, int $index): array
    {
        $key = is_array($tap) && is_string($tap['idempotency_key'] ?? null)
            ? trim($tap['idempotency_key'])
            : null;

        $base = [
            'index' => $index,
            'idempotency_key' => $key,
        ];

        if ($key === null || $key === '' || strlen($key) > 64) {
            return $base + [
                'status' => 'discard',
                'message' => 'idempotency_key tidak valid.',
            ];
        }

        $uid = is_array($tap) && is_string($tap['rfid_uid'] ?? null)
            ? strtoupper(trim($tap['rfid_uid']))
            : '';

        if (! preg_match('/^[0-9A-F]{8}$/', $uid)) {
            return $base + [
                'status' => 'discard',
                'message' => 'rfid_uid tidak valid.',
            ];
        }

        $orderId = is_array($tap) && is_string($tap['order_id'] ?? null)
            ? $tap['order_id']
            : '';

        if (! Str::isUuid($orderId)) {
            return $base + [
                'status' => 'discard',
                'message' => 'order_id tidak valid.',
            ];
        }

        // Perangkat sudah membatalkan tap ini karena tersimpan lebih dari 5
        // menit. Server tetap perlu tahu supaya order tidak menggantung.
        if (($tap['status'] ?? 'pending') === 'voided') {
            return $base + $this->voidFromDevice($console, $orderId);
        }

        try {
            $result = $this->taps->settle(
                consoleId: $console->id,
                rfidUid: $uid,
                orderId: $orderId,
                tapIdempotencyKey: $key,
            );
        } catch (Throwable $e) {
            Log::channel('audit')->error('Console sync tap failed', [
                'console_id' => $console->id,
                'idempotency_key' => $key,
                'order_id' => $orderId,
                'exception' => $e->getMessage(),
            ]);

            return $base + [
                'status' => 'error',
                'message' => 'Gagal memproses tap; coba lagi.',
            ];
        }

        return $base + $this->mapOutcome($result);
    }

    /**
     * @param  array{outcome: string, transaction: ?Transaction, buyer: ?\App\Models\User, reason: ?string}  $result
     * @return array<string, mixed>
     */
    private function mapOutcome(array $result): array
    {
        /** @var Transaction|null $transaction */
        $transaction = $result['transaction'];
        $buyer = $result['buyer'];

        return match ($result['outcome']) {
            RfidTapService::OUTCOME_COMPLETED => $this->successPayload('success', $transaction, $buyer),
            RfidTapService::OUTCOME_DUPLICATE => $this->successPayload('duplicate', $transaction, $buyer),
            RfidTapService::OUTCOME_CARD_INVALID => [
                'status' => 'card_invalid',
                'transaction_id' => $transaction?->id,
                'message' => $result['reason'] ?? 'Kartu tidak valid.',
            ],
            RfidTapService::OUTCOME_ORDER_NOT_TAPPABLE => [
                'status' => 'void',
                'transaction_id' => $transaction?->id,
                'message' => $result['reason'] ?? 'Order sudah tidak bisa ditap.',
            ],
            RfidTapService::OUTCOME_BALANCE_INSUFFICIENT => [
                'status' => 'insufficient_balance',
                'transaction_id' => $transaction?->id,
                'message' => $result['reason'] ?? 'Saldo tidak mencukupi.',
                'buyer_name' => $buyer?->name,
                'new_balance_cents' => $buyer?->balance_cents,
            ],
            RfidTapService::OUTCOME_STOCK_INSUFFICIENT => [
                'status' => 'insufficient_stock',
                'transaction_id' => $transaction?->id,
                'message' => $result['reason'] ?? 'Stok tidak mencukupi.',
            ],
            default => [
                'status' => 'error',
                'message' => 'Kesalahan tidak diketahui.',
            ],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function successPayload(string $status, ?Transaction $transaction, ?\App\Models\User $buyer): array
    {
        if (! $transaction) {
            return ['status' => $status, 'message' => 'Transaksi tidak ditemukan.'];
        }

        return [
            'status' => $status,
            'transaction_id' => $transaction->id,
            'receipt_no' => $transaction->receipt_no,
            'total_cents' => $transaction->total_cents,
            'total_formatted' => Rupiah::format($transaction->total_cents),
            'buyer_name' => $buyer?->name,
            'new_balance_cents' => $buyer?->balance_cents,
            'message' => $status === 'duplicate'
                ? 'Tap ini sudah pernah diproses.'
                : 'Transaksi selesai.',
        ];
    }

    /**
     * Tap yang sudah dibatalkan perangkat karena melewati TTL 5 menit.
     *
     * @return array<string, mixed>
     */
    private function voidFromDevice(Console $console, string $orderId): array
    {
        $order = Transaction::query()
            ->where('id', $orderId)
            ->where('console_id', $console->id)
            ->first();

        if (! $order) {
            return [
                'status' => 'void',
                'transaction_id' => null,
                'message' => 'Order tidak ditemukan pada console ini.',
            ];
        }

        // Perangkat hanya membatalkan tap setelah 5 menit, sedangkan masa tap
        // order 60 detik. Kalau order ini masih segar, jam perangkat yang salah
        // (bukan tap-nya yang basi) — jangan biarkan perangkat membatalkan
        // order yang masih sah. Scheduler tetap akan membatalkannya nanti.
        if ($order->expires_at !== null && $order->expires_at->isFuture()) {
            return [
                'status' => 'void',
                'transaction_id' => $order->id,
                'message' => 'Order masih dalam masa tap; laporan void diabaikan.',
            ];
        }

        $voided = $this->voids->voidIfAwaiting(
            orderId: $orderId,
            errorCode: 'QUEUE_EXPIRED',
            errorMessage: 'Tap tersimpan di perangkat melewati batas 5 menit.',
            auditAction: 'transaction.voided_queue_expired',
            reason: 'antrean perangkat kedaluwarsa',
            consoleId: $console->id,
        );

        if ($voided) {
            return [
                'status' => 'void',
                'transaction_id' => $order->id,
                'message' => 'Order dibatalkan karena tap terlalu lama di antrean.',
            ];
        }

        // Order sempat dibayar tap lain sebelum laporan ini sampai. Jangan
        // bilang "void": pembeli sudah terpotong saldonya.
        $fresh = $order->fresh();

        if ($fresh?->isCompleted()) {
            return ['status' => 'duplicate'] + $this->successPayload('duplicate', $fresh, $fresh->buyer);
        }

        return [
            'status' => 'void',
            'transaction_id' => $order->id,
            'message' => 'Order sudah tidak menunggu tap.',
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function recordQueueState(Console $console, array $validated): void
    {
        $attributes = [
            'last_heartbeat_at' => now(),
            'status' => 'online',
        ];

        // 0 adalah nilai yang sah ("antrean kosong"), jadi jangan pakai ??.
        if (array_key_exists('queue_pending', $validated) && $validated['queue_pending'] !== null) {
            $attributes['queue_pending'] = $validated['queue_pending'];
            $attributes['queue_reported_at'] = now();
        }

        if (array_key_exists('queue_failed', $validated) && $validated['queue_failed'] !== null) {
            $attributes['queue_failed'] = $validated['queue_failed'];
            $attributes['queue_reported_at'] = now();
        }

        $console->forceFill($attributes)->save();
    }
}
