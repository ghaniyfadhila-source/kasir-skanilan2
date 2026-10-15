<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\MenuItem;
use App\Models\RfidCard;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Penyelesaian pembayaran RFID: tap kartu -> potong saldo -> selesaikan order.
 *
 * Semua yang menyentuh saldo, stok, dan status transaksi dijalankan dalam satu
 * transaksi DB supaya tidak pernah ada keadaan setengah jadi (saldo terpotong
 * tapi order tetap `awaiting_rfid`, atau sebaliknya).
 */
class RfidTapService
{
    public const OUTCOME_COMPLETED = 'completed';
    public const OUTCOME_DUPLICATE = 'duplicate';
    public const OUTCOME_CARD_INVALID = 'card_invalid';
    public const OUTCOME_ORDER_NOT_TAPPABLE = 'order_not_tappable';
    public const OUTCOME_BALANCE_INSUFFICIENT = 'balance_insufficient';
    public const OUTCOME_STOCK_INSUFFICIENT = 'stock_insufficient';

    /**
     * @param  string  $consoleId  UUID console yang melakukan tap
     * @return array{outcome: string, transaction: ?Transaction, buyer: ?User, reason: ?string}
     */
    public function settle(
        string $consoleId,
        string $rfidUid,
        string $orderId,
        ?string $tapIdempotencyKey = null
    ): array {
        $uid = strtoupper(trim($rfidUid));

        // Idempotensi tap: kunci yang sama wajib menghasilkan hasil yang sama.
        // Dicek sebelum membuka transaksi supaya tidak mengunci baris apa pun.
        if ($tapIdempotencyKey !== null) {
            $previous = Transaction::where('tap_idempotency_key', $tapIdempotencyKey)->first();

            if ($previous) {
                return $this->result(self::OUTCOME_DUPLICATE, $previous, $previous->buyer);
            }
        }

        return DB::transaction(function () use ($consoleId, $uid, $orderId, $tapIdempotencyKey) {
            $card = RfidCard::where('rfid_uid', $uid)
                ->where('is_active', true)
                ->whereNull('revoked_at')
                ->first();

            if (! $card) {
                return $this->result(self::OUTCOME_CARD_INVALID, reason: 'Kartu tidak valid atau tidak aktif.');
            }

            $order = Transaction::where('id', $orderId)
                ->where('console_id', $consoleId)
                ->lockForUpdate()
                ->first();

            if (! $order) {
                return $this->result(self::OUTCOME_ORDER_NOT_TAPPABLE, reason: 'Order tidak ditemukan pada console ini.');
            }

            // Order yang sudah selesai, batal, atau kedaluwarsa tidak boleh diproses lagi.
            if (! $order->canBePaidByTap()) {
                return $this->result(
                    self::OUTCOME_ORDER_NOT_TAPPABLE,
                    $order,
                    reason: $order->isExpired() ? 'Order sudah kedaluwarsa.' : 'Order sudah diproses.'
                );
            }

            // Kunci baris pembeli supaya dua tap bersamaan tidak sama-sama melihat
            // saldo cukup lalu dua-duanya memotong.
            $buyer = User::where('id', $card->user_id)->lockForUpdate()->first();

            if (! $buyer) {
                return $this->result(
                    self::OUTCOME_CARD_INVALID,
                    $order,
                    reason: 'Kartu tidak tertaut ke pengguna yang valid.'
                );
            }

            if ($buyer->balance_cents < $order->total_cents) {
                return $this->result(
                    self::OUTCOME_BALANCE_INSUFFICIENT,
                    $order,
                    $buyer,
                    'Saldo tidak mencukupi.'
                );
            }

            $items = $order->transactionItems()->with('menuItem')->get();

            // Stok dicek di saat tap (bukan saat order), karena stok bisa habis
            // di antara pembuatan order dan tap pembeli.
            foreach ($items as $item) {
                $menuItem = $item->menuItem;

                if (! $menuItem || ! $menuItem->is_active) {
                    return $this->result(
                        self::OUTCOME_STOCK_INSUFFICIENT,
                        $order,
                        $buyer,
                        'Salah satu menu sudah tidak tersedia.'
                    );
                }

                if ($menuItem->stock !== -1 && $menuItem->stock < $item->quantity) {
                    return $this->result(
                        self::OUTCOME_STOCK_INSUFFICIENT,
                        $order,
                        $buyer,
                        "Stok {$menuItem->name} tidak mencukupi (tersisa {$menuItem->stock})."
                    );
                }
            }

            $order->forceFill([
                'user_id' => $buyer->id,
                'status' => Transaction::STATUS_COMPLETED,
                'completed_at' => now(),
                'synced_at' => now(),
                'tap_idempotency_key' => $tapIdempotencyKey,
                'receipt_no' => Transaction::generateReceiptNo(),
            ])->save();

            $buyer->decrement('balance_cents', $order->total_cents);

            foreach ($items as $item) {
                if ($item->menuItem->stock !== -1) {
                    MenuItem::where('id', $item->menu_item_id)->decrement('stock', $item->quantity);
                }
            }

            $balanceAfter = $buyer->fresh()->balance_cents;

            AuditLog::create([
                'console_id' => $order->console_id,
                'user_id' => $buyer->id,
                'action' => 'transaction.completed',
                'metadata' => [
                    'transaction_id' => $order->id,
                    'receipt_no' => $order->receipt_no,
                    'total_cents' => $order->total_cents,
                    'rfid_uid' => $uid,
                    'balance_after_cents' => $balanceAfter,
                ],
            ]);

            return $this->result(
                self::OUTCOME_COMPLETED,
                $order->fresh(['buyer', 'transactionItems.menuItem']),
                $buyer->fresh()
            );
        });
    }

    /**
     * @return array{outcome: string, transaction: ?Transaction, buyer: ?User, reason: ?string}
     */
    private function result(
        string $outcome,
        ?Transaction $transaction = null,
        ?User $buyer = null,
        ?string $reason = null
    ): array {
        return compact('outcome', 'transaction', 'buyer', 'reason');
    }
}