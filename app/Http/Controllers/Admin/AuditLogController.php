<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Console;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuditLogController extends Controller
{
    public function index(Request $request)
    {
        [$start, $end, $query] = $this->applyFilters($request);

        $logs = $query
            ->with(['actor', 'user', 'console'])
            ->orderByDesc('created_at')
            ->paginate(50)
            ->withQueryString();

        return view('admin.audit-logs.index', [
            'logs' => $logs,
            'start' => $start,
            'end' => $end,
            'actions' => $this->actionList(),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        [$start, $end, $query] = $this->applyFilters($request);

        $filename = sprintf('audit_logs_%s_%s.csv', $start->toDateString(), $end->toDateString());

        return response()->streamDownload(function () use ($query) {
            $handle = fopen('php://output', 'wb');

            // BOM UTF-8 supaya Excel di Windows bisa membaca karakter Indonesia.
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, [
                'Waktu', 'Aksi', 'Pelaku (actor)', 'Subjek', 'Console',
                'IP', 'Metadata',
            ]);

            $query->with(['actor', 'user', 'console'])
                ->orderBy('created_at')
                ->chunkById(200, function ($rows) use ($handle) {
                    foreach ($rows as $log) {
                        fputcsv($handle, [
                            $log->created_at?->toDateTimeString(),
                            $log->action,
                            $log->actor?->name ?? '-',
                            $log->user?->name ?? '-',
                            $log->console?->name ?? '-',
                            $log->ip_address ?? '-',
                            $log->metadata ? json_encode($log->metadata, JSON_UNESCAPED_UNICODE) : '',
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
     * @return array{0: Carbon, 1: Carbon, 2: Builder<AuditLog>}
     */
    private function applyFilters(Request $request): array
    {
        $today = now()->startOfDay();

        $end = $request->filled('end') ? Carbon::parse($request->end)->startOfDay() : $today->copy();
        $start = $request->filled('start') ? Carbon::parse($request->start)->startOfDay() : $today->copy()->subDays(29);

        if ($start->gt($end)) {
            [$start, $end] = [$end, $start];
        }

        $query = AuditLog::query()->between($start, $end);

        if ($request->filled('action') && $request->action !== 'all') {
            $query->where('action', $request->action);
        }

        if ($request->filled('user')) {
            $search = $request->string('user')->toString();
            $query->whereHas('user', fn ($q) => $q->where('name', 'like', "%{$search}%"));
        }

        if ($request->filled('actor')) {
            $search = $request->string('actor')->toString();
            $query->whereHas('actor', fn ($q) => $q->where('name', 'like', "%{$search}%"));
        }

        if ($request->filled('console')) {
            $search = $request->string('console')->toString();
            $query->whereHas('console', fn ($q) => $q->where('name', 'like', "%{$search}%"));
        }

        return [$start, $end, $query];
    }

    /**
     * Daftar aksi yang dikenal, diambil dari konstanta AuditLogger::ACT_*.
     */
    private function actionList(): array
    {
        return collect((new \ReflectionClass(AuditLogger::class))->getConstants())
            ->filter(fn ($value, $name) => str_starts_with($name, 'ACT_'))
            ->map(fn ($value) => $value)
            ->sort()
            ->values()
            ->all();
    }
}