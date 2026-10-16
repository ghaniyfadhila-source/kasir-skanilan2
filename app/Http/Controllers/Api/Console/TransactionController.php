<?php

namespace App\Http\Controllers\Api\Console;

use App\Http\Controllers\Controller;
use App\Models\RfidCard;
use App\Models\Transaction;
use App\Services\RfidTapService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Endpoint untuk perangkat kasir (ESP8266).
 * Semua route memakai middleware `console.auth` (X-Device-ID + X-API-Key).
 */
class TransactionController extends Controller
{
    public function __construct(private readonly RfidTapService $taps)
    {
    }

    /**
     * POST /api/console/rfid-tap
     *
     * Body: { rfid_uid, order_id, idempotency_key? }
     */
    public function rfidTap(Request $request): JsonResponse
    {
        $console = $this->console($request);

        $validated = $request->validate([
            'rfid_uid' => ['required', 'string', 'regex:/^[0-9A-Fa-f]{8}$/'],
            'order_id' => ['required', 'uuid', 'exists:transactions,id'],
            'idempotency_key' => ['nullable', 'string', 'max:64'],
        ]);

        $result = $this->taps->settle(
            consoleId: $console->id,
            rfidUid: $validated['rfid_uid'],
            orderId: $validated['order_id'],
            tapIdempotencyKey: $validated['idempotency_key'] ?? null,
        );

        return $this->respond($result, $console->device_id);
    }

    /**
     * GET /api/console/balance/{uid}
     *
     * Dipakai LCD untuk menampilkan sisa saldo sebelum konfirmasi.
     * Hanya mengembalikan nama & saldo, tanpa mutate.
     */
    public function balance(Request $request, string $uid): JsonResponse
    {
        $card = RfidCard::where('rfid_uid', strtoupper($uid))
            ->whereNull('revoked_at')
            ->where('is_active', true)
            ->with('user')
            ->first();

        if (! $card || ! $card->user) {
            return $this->error('card_invalid', 'Kartu tidak valid atau tidak aktif.', 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'rfid_uid' => $card->rfid_uid,
                'name' => $card->user->name,
                'balance_cents' => $card->user->balance_cents,
                'balance_formatted' => $this->rupiah($card->user->balance_cents),
            ],
        ]);
    }

    /**
     * POST /api/console/heartbeat
     *
     * Body: { ip_address?, wifi_rssi?, firmware_version?, status? }
     */
    public function heartbeat(Request $request): JsonResponse
    {
        $console = $this->console($request);

        $validated = $request->validate([
            'ip_address' => ['nullable', 'string', 'max:45'],
            'wifi_rssi' => ['nullable', 'integer', 'between:-100,0'],
            'firmware_version' => ['nullable', 'string', 'max:20'],
            'status' => ['nullable', 'in:online,offline,maintenance'],
        ]);

        $console->update(array_merge($validated, [
            'status' => $validated['status'] ?? 'online',
            'last_heartbeat_at' => now(),
        ]));

        return response()->json([
            'success' => true,
            'data' => [
                'server_time' => now()->toIso8601String(),
                // Placeholder sampai Ticket 09 (Realtime) aktif.
                'queue_commands' => [],
            ],
        ]);
    }

    /**
     * GET /api/console/pending-order
     *
     * Memberi tahu perangkat kasir order mana yang sedang menunggu tap RFID.
     *
     * Kenapa perlu: notifikasi push lewat Realtime (channel console:{device_id})
     * baru ada di Ticket 09. Tanpa Supabase project, perangkat tidak punya
     * cara lain untuk tahu order aktif, sehingga LCD tidak bisa menampilkan
     * "TAP RFID | Rp X". Endpoint ini menutup celah itu lewat polling REST.
     */
    public function pendingOrder(Request $request): JsonResponse
    {
        $console = $this->console($request);

        $order = Transaction::query()
            ->where('console_id', $console->id)
            ->awaitingRfid()
            ->whereNotNull('expires_at')
            ->where('expires_at', '>', now())
            ->orderBy('created_at')
            ->first();

        if (! $order) {
            return response()->json([
                'success' => true,
                'data' => null,
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'order_id' => $order->id,
                'status' => $order->status,
                'total_cents' => $order->total_cents,
                'expires_at' => $order->expires_at?->toIso8601String(),
            ],
        ]);
    }

    // ------------------------------------------------------------------

    private function respond(array $result, string $deviceId): JsonResponse
    {
        return match ($result['outcome']) {
            RfidTapService::OUTCOME_COMPLETED,
            RfidTapService::OUTCOME_DUPLICATE => $this->success($result),

            RfidTapService::OUTCOME_CARD_INVALID => $this->error('card_invalid', $result['reason'], 404),

            RfidTapService::OUTCOME_ORDER_NOT_TAPPABLE => $this->error('order_not_tappable', $result['reason'], 422),

            RfidTapService::OUTCOME_BALANCE_INSUFFICIENT => $this->error('insufficient_balance', $result['reason'], 422),

            RfidTapService::OUTCOME_STOCK_INSUFFICIENT => $this->error('insufficient_stock', $result['reason'], 422),

            default => $this->error('unknown_error', 'Kesalahan tidak diketahui.', 500),
        };
    }

    private function success(array $result): JsonResponse
    {
        /** @var Transaction $transaction */
        $transaction = $result['transaction'];
        $buyer = $result['buyer'];
        $duplicate = $result['outcome'] === RfidTapService::OUTCOME_DUPLICATE;

        return response()->json([
            'success' => true,
            'duplicate' => $duplicate,
            'data' => [
                'transaction_id' => $transaction->id,
                'receipt_no' => $transaction->receipt_no,
                'status' => $transaction->status,
                'total_cents' => $transaction->total_cents,
                'total_formatted' => $this->rupiah($transaction->total_cents),
                'buyer_name' => $buyer?->name,
                'new_balance_cents' => $buyer?->balance_cents,
                'new_balance_formatted' => $this->rupiah($buyer?->balance_cents ?? 0),
                'completed_at' => $transaction->completed_at?->toIso8601String(),
            ],
        ]);
    }

    private function error(string $code, ?string $message, int $status): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error_code' => $code,
            'message' => $message,
        ], $status);
    }

    private function rupiah(?int $cents): string
    {
        return 'Rp '.number_format(($cents ?? 0) / 100, 0, ',', '.');
    }
}