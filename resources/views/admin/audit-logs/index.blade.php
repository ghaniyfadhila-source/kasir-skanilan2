<x-layouts.app :title="'Audit Log'">
    <div class="space-y-6">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">Jejak Audit</h1>
                <p class="mt-1 text-gray-600">Siapa melakukan apa, dan kapan.</p>
            </div>
            <a href="{{ route('admin.audit-logs.export', request()->only(['start','end','action','user','actor','console'])) }}"
               class="inline-flex items-center px-4 py-2 bg-emerald-600 text-white font-medium rounded-lg hover:bg-emerald-700 transition-colors">
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 15V3"></path>
                </svg>
                Export CSV
            </a>
        </div>

        <!-- Filter -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4">
            <form method="GET" action="{{ route('admin.audit-logs.index') }}" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-4">
                <div>
                    <label for="start" class="block text-sm font-medium text-gray-700 mb-1">Dari</label>
                    <input type="date" id="start" name="start" value="{{ $start->toDateString() }}"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 transition-colors">
                </div>
                <div>
                    <label for="end" class="block text-sm font-medium text-gray-700 mb-1">Sampai</label>
                    <input type="date" id="end" name="end" value="{{ $end->toDateString() }}"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 transition-colors">
                </div>
                <div>
                    <label for="action" class="block text-sm font-medium text-gray-700 mb-1">Aksi</label>
                    <select id="action" name="action"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 transition-colors">
                        <option value="all">Semua aksi</option>
                        @foreach ($actions as $action)
                            <option value="{{ $action }}" {{ request('action') === $action ? 'selected' : '' }}>
                                {{ $action }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="actor" class="block text-sm font-medium text-gray-700 mb-1">Pelaku</label>
                    <input type="text" id="actor" name="actor" value="{{ request('actor') }}" placeholder="Nama admin"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 transition-colors">
                </div>
                <div>
                    <label for="user" class="block text-sm font-medium text-gray-700 mb-1">Subjek</label>
                    <input type="text" id="user" name="user" value="{{ request('user') }}" placeholder="Nama siswa"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 transition-colors">
                </div>
                <div class="flex items-end">
                    <button type="submit" class="w-full px-4 py-2 bg-blue-600 text-white font-medium rounded-lg hover:bg-blue-700 transition-colors">
                        Filter
                    </button>
                </div>
            </form>
        </div>

        <!-- Tabel -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
            @if ($logs->isEmpty())
                <div class="text-center py-12">
                    <svg class="w-16 h-16 mx-auto text-gray-300 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                    </svg>
                    <h3 class="text-lg font-medium text-gray-900 mb-1">Belum ada jejak audit</h3>
                    <p class="text-gray-500">Coba ubah filter tanggal atau aksi.</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-gray-50 border-b border-gray-200">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Waktu</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Aksi</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Pelaku</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Subjek</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Console</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">IP</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Metadata</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            @foreach ($logs as $log)
                                <tr class="hover:bg-gray-50 align-top">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        {{ $log->created_at->format('d M Y H:i:s') }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <code class="text-xs font-mono px-2 py-1 rounded bg-gray-100 text-gray-800">{{ $log->action }}</code>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm">
                                        <span class="text-gray-900">{{ $log->actor?->name ?? 'Sistem' }}</span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        {{ $log->user?->name ?? '-' }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        {{ $log->console?->name ?? '-' }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-xs font-mono text-gray-500">
                                        {{ $log->ip_address ?? '-' }}
                                    </td>
                                    <td class="px-6 py-4 text-xs">
                                        @if ($log->metadata)
                                            <details>
                                                <summary class="cursor-pointer text-blue-600 hover:text-blue-900">
                                                    {{ count($log->metadata) }} field
                                                </summary>
                                                <pre class="mt-2 p-3 bg-gray-900 text-gray-100 rounded-lg overflow-x-auto text-[11px] leading-relaxed">{{ json_encode($log->metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                                            </details>
                                        @else
                                            <span class="text-gray-400">-</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="px-6 py-4 border-t border-gray-200">
                    {{ $logs->links() }}
                </div>
            @endif
        </div>
    </div>
</x-layouts.app>