<?php

namespace App\Services;

use App\Models\Console;
use App\Models\MenuItem;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Agregasi laporan penjualan.
 *
 * Semua angka berasal dari transaksi berstatus `completed` — order `awaiting_rfid`
 * belum dibayar dan `voided` tidak menghasilkan pendapatan.
 */
class ReportService
{
    /**
     * Ringkasan untuk kartu statistik.
     *
     * @return array{transactions: int, revenue_cents: int, unique_buyers: int, avg_cents: int}
     */
    public function summary(Carbon $start, Carbon $end): array
    {
        $row = Transaction::query()
            ->completedBetween($start, $end)
            ->selectRaw('COUNT(*) as c, COALESCE(SUM(total_cents), 0) as rev, COUNT(DISTINCT user_id) as buyers')
            ->first();

        $count = (int) ($row->c ?? 0);
        $revenue = (int) ($row->rev ?? 0);

        return [
            'transactions' => $count,
            'revenue_cents' => $revenue,
            'unique_buyers' => (int) ($row->buyers ?? 0),
            'avg_cents' => $count > 0 ? intdiv($revenue, $count) : 0,
        ];
    }

    /**
     * Pendapatan per hari, termasuk hari tanpa transaksi (nilai 0)
     * supaya grafik batang tidak melompat-lompat.
     *
     * @return array<int, array{date: string, revenue_cents: int, transactions: int}>
     */
    public function revenuePerDay(Carbon $start, Carbon $end): array
    {
        $expression = $this->dateExpression('completed_at');

        $rows = Transaction::query()
            ->completedBetween($start, $end)
            ->selectRaw("$expression as day, COALESCE(SUM(total_cents), 0) as rev, COUNT(*) as c")
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->keyBy(fn ($r) => (string) $r->day);

        $result = [];
        $cursor = $start->copy()->startOfDay();

        while ($cursor->lte($end)) {
            $key = $cursor->toDateString();
            $row = $rows->get($key);

            $result[] = [
                'date' => $key,
                'revenue_cents' => (int) ($row->rev ?? 0),
                'transactions' => (int) ($row->c ?? 0),
            ];

            $cursor->addDay();
        }

        return $result;
    }

    /**
     * 10 menu item terlaris dihitung dari quantity terjual.
     *
     * @return array<int, array{name: string, quantity: int, revenue_cents: int}>
     */
    public function topMenuItems(Carbon $start, Carbon $end, int $limit = 10): array
    {
        return DB::table('transaction_items')
            ->join('transactions', 'transactions.id', '=', 'transaction_items.transaction_id')
            ->join('menu_items', 'menu_items.id', '=', 'transaction_items.menu_item_id')
            ->where('transactions.status', Transaction::STATUS_COMPLETED)
            ->whereBetween('transactions.completed_at', [
                $start->copy()->startOfDay(),
                $end->copy()->endOfDay(),
            ])
            ->groupBy('menu_items.id', 'menu_items.name')
            ->orderByDesc('qty')
            ->orderBy('menu_items.name')
            ->limit($limit)
            ->selectRaw('menu_items.name as name, SUM(transaction_items.quantity) as qty, SUM(transaction_items.quantity * transaction_items.unit_price_cents) as rev')
            ->get()
            ->map(fn ($r) => [
                'name' => (string) $r->name,
                'quantity' => (int) $r->qty,
                'revenue_cents' => (int) $r->rev,
            ])
            ->all();
    }

    /**
     * Kinerja per kasir.
     *
     * @return array<int, array{cashier: string, transactions: int, revenue_cents: int}>
     */
    public function byCashier(Carbon $start, Carbon $end): array
    {
        return DB::table('transactions')
            ->leftJoin('users', 'users.id', '=', 'transactions.cashier_id')
            ->where('transactions.status', Transaction::STATUS_COMPLETED)
            ->whereBetween('transactions.completed_at', [
                $start->copy()->startOfDay(),
                $end->copy()->endOfDay(),
            ])
            ->groupBy('transactions.cashier_id', 'users.name')
            ->orderByDesc('rev')
            ->selectRaw("COALESCE(users.name, 'Tanpa kasir') as cashier, COUNT(*) as c, COALESCE(SUM(transactions.total_cents), 0) as rev")
            ->get()
            ->map(fn ($r) => [
                'cashier' => (string) $r->cashier,
                'transactions' => (int) $r->c,
                'revenue_cents' => (int) $r->rev,
            ])
            ->all();
    }

    /**
     * Pendapatan per console — berguna saat ada lebih dari satu kasir.
     *
     * @return array<int, array{console: string, transactions: int, revenue_cents: int}>
     */
    public function byConsole(Carbon $start, Carbon $end): array
    {
        return DB::table('transactions')
            ->leftJoin('consoles', 'consoles.id', '=', 'transactions.console_id')
            ->where('transactions.status', Transaction::STATUS_COMPLETED)
            ->whereBetween('transactions.completed_at', [
                $start->copy()->startOfDay(),
                $end->copy()->endOfDay(),
            ])
            ->groupBy('transactions.console_id', 'consoles.name')
            ->orderByDesc('rev')
            ->selectRaw("COALESCE(consoles.name, 'Console dihapus') as console, COUNT(*) as c, COALESCE(SUM(transactions.total_cents), 0) as rev")
            ->get()
            ->map(fn ($r) => [
                'console' => (string) $r->console,
                'transactions' => (int) $r->c,
                'revenue_cents' => (int) $r->rev,
            ])
            ->all();
    }

    /**
     * Ekspresi SQL untuk memotong timestamp ke level hari.
     * Postgres memakai DATE_TRUNC, SQLite/MySQL memakai DATE.
     */
    private function dateExpression(string $column): string
    {
        return match (DB::connection()->getDriverName()) {
            'pgsql' => "DATE_TRUNC('day', {$column})::date",
            default => "DATE({$column})",
        };
    }

    public static function rupiah(?int $cents): string
    {
        return \App\Support\Rupiah::format($cents ?? 0);
    }
}