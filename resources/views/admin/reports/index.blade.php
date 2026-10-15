<x-layouts.app :title="'Laporan'">
    <div class="space-y-6">
        <!-- Header -->
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">Laporan Penjualan</h1>
                <p class="mt-1 text-gray-600">{{ $start->format('d M Y') }} &mdash; {{ $end->format('d M Y') }}</p>
            </div>
            <a href="{{ route('admin.reports.export', ['start' => $start->toDateString(), 'end' => $end->toDateString()]) }}"
               class="inline-flex items-center px-4 py-2 bg-emerald-600 text-white font-medium rounded-lg hover:bg-emerald-700 transition-colors">
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 15V3"></path>
                </svg>
                Export CSV
            </a>
        </div>

        <!-- Filter rentang tanggal -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4">
            <form method="GET" action="{{ route('admin.reports.index') }}" class="space-y-4">
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label for="start" class="block text-sm font-medium text-gray-700 mb-1">Dari</label>
                        <input type="date" id="start" name="start" value="{{ $start->toDateString() }}"
                               class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors">
                    </div>
                    <div>
                        <label for="end" class="block text-sm font-medium text-gray-700 mb-1">Sampai</label>
                        <input type="date" id="end" name="end" value="{{ $end->toDateString() }}"
                               class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors">
                    </div>
                    <div class="flex items-end">
                        <button type="submit" class="w-full px-4 py-2.5 bg-blue-600 text-white font-medium rounded-lg hover:bg-blue-700 transition-colors">
                            Terapkan
                        </button>
                    </div>
                </div>

                <!-- Preset -->
                <div class="flex flex-wrap gap-2 pt-2 border-t border-gray-100">
                    <span class="text-sm text-gray-500 self-center">Preset:</span>
                    @php
                        $today = \Illuminate\Support\Carbon::now()->startOfDay();
                        $presets = [
                            'Hari ini' => [$today, $today],
                            'Kemarin' => [$today->copy()->subDay(), $today->copy()->subDay()],
                            '7 hari' => [$today->copy()->subDays(6), $today],
                            '30 hari' => [$today->copy()->subDays(29), $today],
                            'Minggu ini' => [$today->copy()->startOfWeek(), $today],
                            'Minggu lalu' => [$today->copy()->subWeek()->startOfWeek(), $today->copy()->subWeek()->endOfWeek()],
                            'Bulan ini' => [$today->copy()->startOfMonth(), $today],
                            'Bulan lalu' => [$today->copy()->subMonth()->startOfMonth(), $today->copy()->subMonth()->endOfMonth()],
                        ];
                    @endphp
                    @foreach ($presets as $label => [$pStart, $pEnd])
                        <a href="{{ route('admin.reports.index', ['start' => $pStart->toDateString(), 'end' => $pEnd->toDateString()]) }}"
                           class="px-3 py-1.5 text-xs font-medium rounded-lg border transition-colors
                                  {{ $start->toDateString() === $pStart->toDateString() && $end->toDateString() === $pEnd->toDateString()
                                     ? 'bg-blue-600 text-white border-blue-600'
                                     : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50' }}">
                            {{ $label }}
                        </a>
                    @endforeach
                </div>
            </form>
        </div>

        <!-- Kartu ringkasan -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            @php
                $cards = [
                    ['Total Transaksi', number_format($summary['transactions'], 0, ',', '.'), 'transaksi selesai'],
                    ['Total Pendapatan', \App\Services\ReportService::rupiah($summary['revenue_cents']), 'dari transaksi selesai'],
                    ['Pembeli Unik', number_format($summary['unique_buyers'], 0, ',', '.'), 'siswa berbeda'],
                    ['Rata-rata', \App\Services\ReportService::rupiah($summary['avg_cents']), 'per transaksi'],
                ];
            @endphp
            @foreach ($cards as $i => [$label, $value, $hint])
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-5">
                    <p class="text-sm text-gray-500">{{ $label }}</p>
                    <p class="mt-1 text-2xl font-bold text-gray-900 tabular-nums">{{ $value }}</p>
                    <p class="mt-1 text-xs text-gray-400">{{ $hint }}</p>
                </div>
            @endforeach
        </div>

        <!-- Grafik -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <h2 class="text-lg font-semibold text-gray-900 mb-4">Pendapatan per Hari</h2>
                <div class="h-64"><canvas id="chartRevenue"></canvas></div>
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <h2 class="text-lg font-semibold text-gray-900 mb-4">10 Menu Terlaris</h2>
                <div class="h-64"><canvas id="chartItems"></canvas></div>
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <h2 class="text-lg font-semibold text-gray-900 mb-4">Penjualan per Kasir</h2>
                <div class="h-64"><canvas id="chartCashier"></canvas></div>
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <h2 class="text-lg font-semibold text-gray-900 mb-4">Penjualan per Console</h2>
                <div class="h-64"><canvas id="chartConsole"></canvas></div>
            </div>
        </div>

        <!-- Tabel transaksi -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200">
                <h2 class="text-lg font-semibold text-gray-900">Transaksi Terakhir</h2>
            </div>

            @if ($transactions->isEmpty())
                <div class="text-center py-12">
                    <p class="text-gray-500">Tidak ada transaksi selesai pada rentang ini.</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-gray-50 border-b border-gray-200">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Waktu</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">No Struk</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Pembeli</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Kasir</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Item</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Total</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            @foreach ($transactions as $tx)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        {{ $tx->completed_at?->format('d M Y H:i') }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <code class="text-sm font-mono text-gray-900">{{ $tx->receipt_no }}</code>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        {{ $tx->buyer?->name ?? '-' }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        {{ $tx->cashier?->name ?? '-' }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        {{ $tx->transactionItems->map(fn ($i) => ($i->menuItem?->name ?? '-').' ×'.$i->quantity)->join(', ') ?: '-' }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium tabular-nums text-gray-900">
                                        {{ \App\Services\ReportService::rupiah($tx->total_cents) }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="px-6 py-4 border-t border-gray-200">
                    {{ $transactions->links() }}
                </div>
            @endif
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script>
        document.addEventListener('alpine:init', () => {
            // Placeholder agar Alpine tidak complain; grafik dimuat manual di bawah.
        });
    </script>
    <script>
        const rupiah = (cents) => 'Rp ' + new Intl.NumberFormat('id-ID').format(cents / 100);

        async function loadCharts() {
            const start = {{ $start->toDateString() }};
            const end = {{ $end->toDateString() }};

            const res = await fetch(
                '{{ route('admin.reports.data') }}?start=' + start + '&end=' + end,
                { headers: { 'Accept': 'application/json' } }
            );
            const json = await res.json();
            const d = json.data;

            Chart.defaults.font.family = 'Inter, sans-serif';
            Chart.defaults.color = '#6B7280';

            // 1. Pendapatan per hari
            new Chart(document.getElementById('chartRevenue'), {
                type: 'bar',
                data: {
                    labels: d.revenue_per_day.map(r => r.date),
                    datasets: [{
                        label: 'Pendapatan',
                        data: d.revenue_per_day.map(r => r.revenue_cents / 100),
                        backgroundColor: '#3B82F6',
                        borderRadius: 4,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: { y: { beginAtZero: true, ticks: { callback: v => 'Rp ' + v } } }
                }
            });

            // 2. Menu terlaris
            new Chart(document.getElementById('chartItems'), {
                type: 'doughnut',
                data: {
                    labels: d.top_items.map(i => i.name),
                    datasets: [{
                        data: d.top_items.map(i => i.quantity),
                        backgroundColor: ['#3B82F6','#10B981','#F59E0B','#EF4444','#8B5CF6','#EC4899','#14B8A6','#F97316','#6366F1','#84CC16'],
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { position: 'right' } }
                }
            });

            // 3. Per kasir
            new Chart(document.getElementById('chartCashier'), {
                type: 'bar',
                data: {
                    labels: d.by_cashier.map(c => c.cashier),
                    datasets: [{
                        label: 'Pendapatan',
                        data: d.by_cashier.map(c => c.revenue_cents / 100),
                        backgroundColor: '#10B981',
                        borderRadius: 4,
                    }]
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: { x: { beginAtZero: true, ticks: { callback: v => 'Rp ' + v } } }
                }
            });

            // 4. Per console
            new Chart(document.getElementById('chartConsole'), {
                type: 'bar',
                data: {
                    labels: d.by_console.map(c => c.console),
                    datasets: [{
                        label: 'Pendapatan',
                        data: d.by_console.map(c => c.revenue_cents / 100),
                        backgroundColor: '#8B5CF6',
                        borderRadius: 4,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: { y: { beginAtZero: true, ticks: { callback: v => 'Rp ' + v } } }
                }
            });
        }

        loadCharts();
    </script>
</x-layouts.app>