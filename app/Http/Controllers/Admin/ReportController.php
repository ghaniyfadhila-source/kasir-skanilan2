<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Services\ReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

class ReportController extends Controller
{
    public function __construct(private readonly ReportService $reports)
    {
    }

    public function index(Request $request)
    {
        [$start, $end] = $this->resolveRange($request);

        $summary = $this->reports->summary($start, $end);

        $transactions = Transaction::query()
            ->completedBetween($start, $end)
            ->with(['buyer', 'cashier', 'console', 'transactionItems.menuItem'])
            ->orderByDesc('completed_at')
            ->paginate(25)
            ->withQueryString();

        return view('admin.reports.index', compact('summary', 'transactions', 'start', 'end'));
    }

    /**
     * GET /admin/reports/data?start=...&end=...
     * JSON untuk Chart.js. D-cache 5 menit dengan kunci ikut rentang tanggal.
     */
    public function data(Request $request)
    {
        [$start, $end] = $this->resolveRange($request);

        $payload = cache()->remember(
            $this->cacheKey($start, $end),
            now()->addMinutes(5),
            fn () => [
                'summary' => $this->reports->summary($start, $end),
                'revenue_per_day' => $this->reports->revenuePerDay($start, $end),
                'top_items' => $this->reports->topMenuItems($start, $end),
                'by_cashier' => $this->reports->byCashier($start, $end),
                'by_console' => $this->reports->byConsole($start, $end),
            ]
        );

        return response()->json([
            'success' => true,
            'data' => $payload + [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
            ],
        ]);
    }

    /**
     * GET /admin/reports/export?start=...&end=...
     * Streaming CSV supaya range 1 tahun tidak kehabisan timeout/memori.
     */
    public function export(Request $request): StreamedResponse
    {
        // streamDownload() mengembalikan kelas Symfony, bukan Illuminate.
        [$start, $end] = $this->resolveRange($request);

        $filename = sprintf('transactions_%s_%s.csv', $start->toDateString(), $end->toDateString());

        Log::channel('audit')->info('Report exported', [
            'admin_id' => Auth::id(),
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
        ]);

        return response()->streamDownload(function () use ($start, $end) {
            $handle = fopen('php://output', 'wb');

            // BOM UTF-8 supaya Excel di Windows membaca karakter Indonesia dengan benar.
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, [
                'Tanggal', 'Waktu', 'No Struk', 'Pembeli', 'Kasir', 'Console',
                'Item', 'Jumlah Item', 'Total (Rp)', 'Status',
            ]);

            $chunk = 200;

            Transaction::query()
                ->completedBetween($start, $end)
                ->with(['buyer', 'cashier', 'console', 'transactionItems.menuItem'])
                ->orderBy('completed_at')
                ->chunkById($chunk, function ($rows) use ($handle) {
                    foreach ($rows as $tx) {
                        $items = $tx->transactionItems->map(
                            fn ($i) => trim(($i->menuItem?->name ?? '-').' x'.$i->quantity)
                        )->implode('; ');

                        fputcsv($handle, [
                            $tx->completed_at?->toDateString(),
                            $tx->completed_at?->toTimeString(),
                            $tx->receipt_no,
                            $tx->buyer?->name ?? '-',
                            $tx->cashier?->name ?? '-',
                            $tx->console?->name ?? '-',
                            $items,
                            $tx->transactionItems->sum('quantity'),
                            number_format($tx->total_cents / 100, 0, ',', '.'),
                            $tx->status,
                        ]);
                    }
                }, 'id');

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Rentang tanggal dari query string, default 7 hari terakhir.
     * Maksimal dibatasi 366 hari agar satu export tidak melumpuhkan server.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolveRange(Request $request): array
    {
        $today = now()->startOfDay();

        $end = $request->filled('end')
            ? Carbon::parse($request->end)->startOfDay()
            : $today->copy();

        $start = $request->filled('start')
            ? Carbon::parse($request->start)->startOfDay()
            : $today->copy()->subDays(6);

        // Tukar bila user mengirim rentang terbalik.
        if ($start->gt($end)) {
            [$start, $end] = [$end, $start];
        }

        if ($start->diffInDays($end) > 366) {
            $start = $end->copy()->subDays(366);
        }

        return [$start, $end];
    }

    private function cacheKey(Carbon $start, Carbon $end): string
    {
        return "reports:{$start->toDateString()}:{$end->toDateString()}";
    }
}