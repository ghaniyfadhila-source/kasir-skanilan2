<x-layouts.app :title="'Detail Console: ' . $console->name">
    <div class="max-w-3xl mx-auto space-y-6">
        <!-- Header -->
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">Detail Console</h1>
                <p class="mt-1 text-gray-600">{{ $console->device_id }}</p>
            </div>
            <div class="flex gap-2">
                <a href="{{ route('admin.consoles.edit', $console) }}"
                   class="px-4 py-2 bg-blue-600 text-white font-medium rounded-lg hover:bg-blue-700 transition-colors">
                    Edit
                </a>
                <a href="{{ route('admin.consoles.index') }}"
                   class="px-4 py-2 text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors">
                    Kembali
                </a>
            </div>
        </div>

        <!-- Info Cards -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <p class="text-sm font-medium text-gray-500">Status</p>
                <p class="mt-2 text-2xl font-bold text-gray-900">
                    <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium {{ $console->getStatusBadgeClass() }}">
                        {{ $console->getStatusLabel() }}
                    </span>
                </p>
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <p class="text-sm font-medium text-gray-500">Firmware</p>
                <p class="mt-2 text-2xl font-bold text-gray-900">{{ $console->firmware_version ?? '-' }}</p>
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <p class="text-sm font-medium text-gray-500">Terakhir Heartbeat</p>
                <p class="mt-2 text-xl font-bold text-gray-900">{{ $console->last_heartbeat_at ? $console->last_heartbeat_at->diffForHumans() : 'Belum pernah' }}</p>
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <p class="text-sm font-medium text-gray-500">Cashier</p>
                <p class="mt-2 text-xl font-bold text-gray-900">{{ $console->cashier?->name ?? 'Belum ditetapkan' }}</p>
            </div>
        </div>

        <!-- Detail Sections -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Basic Info -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Informasi Dasar</h3>
                <dl class="space-y-4">
                    <div>
                        <dt class="text-sm font-medium text-gray-500">Device ID</dt>
                        <dd class="mt-1 font-mono text-gray-900">{{ $console->device_id }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-gray-500">Nama</dt>
                        <dd class="mt-1 text-gray-900">{{ $console->name }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-gray-500">Lokasi</dt>
                        <dd class="mt-1 text-gray-900">{{ $console->location ?? '-' }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-gray-500">Aktif</dt>
                        <dd class="mt-1">
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                                {{ $console->is_active ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">
                                {{ $console->is_active ? 'Ya' : 'Tidak' }}
                            </span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-gray-500">Dibuat</dt>
                        <dd class="mt-1 text-gray-900">{{ $console->created_at->format('d M Y H:i') }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-gray-500">Diperbarui</dt>
                        <dd class="mt-1 text-gray-900">{{ $console->updated_at->format('d M Y H:i') }}</dd>
                    </div>
                </dl>
            </div>

            <!-- Network Info -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Informasi Jaringan</h3>
                <dl class="space-y-4">
                    <div>
                        <dt class="text-sm font-medium text-gray-500">IP Address</dt>
                        <dd class="mt-1 font-mono text-gray-900">{{ $console->ip_address ?? '-' }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-gray-500">WiFi RSSI</dt>
                        <dd class="mt-1 text-gray-900">{{ $console->wifi_rssi ? $console->wifi_rssi . ' dBm' : '-' }}</dd>
                    </div>
                </dl>
            </div>

            <!-- Credentials -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 lg:col-span-2">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold text-gray-900">Kredensial Console</h3>
                    <form method="POST" action="{{ route('admin.consoles.rotate-credentials', $console) }}" class="inline">
                        @csrf
                        <button type="submit"
                                onclick="return confirm('Yakin ingin merotasi kredensial? Kredensial lama akan segera tidak valid.')"
                                class="px-3 py-1.5 bg-orange-600 text-white text-sm font-medium rounded-lg hover:bg-orange-700 transition-colors">
                            Rotasi
                        </button>
                    </form>
                </div>
                @if ($console->credentials)
                    <dl class="space-y-3">
                        <div>
                            <dt class="text-sm font-medium text-gray-500">API Key</dt>
                            <dd class="mt-1 flex items-center gap-2">
                                <code class="flex-1 bg-gray-100 px-3 py-2 rounded font-mono text-sm text-gray-900">{{ $console->credentials->api_key }}</code>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Terakhir Dirotasi</dt>
                            <dd class="mt-1 text-gray-900">{{ $console->credentials->rotated_at ? $console->credentials->rotated_at->format('d M Y H:i') : 'Belum pernah' }}</dd>
                        </div>
                    </dl>
                @else
                    <p class="text-gray-500">Belum ada kredensial. Klik "Rotasi" untuk membuat.</p>
                @endif
            </div>

            <!-- Recent Transactions -->
            @if ($console->transactions->count() > 0)
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 lg:col-span-2">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-semibold text-gray-900">Transaksi Terbaru</h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead>
                                <tr class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider border-b border-gray-200">
                                    <th class="pb-3">Receipt No</th>
                                    <th class="pb-3">Buyer</th>
                                    <th class="pb-3">Total</th>
                                    <th class="pb-3">Status</th>
                                    <th class="pb-3">Waktu</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                @foreach ($console->transactions->take(10) as $transaction)
                                    <tr class="hover:bg-gray-50">
                                        <td class="py-3 font-mono text-sm text-gray-900">{{ $transaction->id }}</td>
                                        <td class="py-3 text-sm text-gray-900">{{ $transaction->buyer?->name ?? 'Unknown' }}</td>
                                        <td class="py-3 text-sm text-gray-900">Rp {{ number_format($transaction->total_cents / 100, 0, ',', '.') }}</td>
                                        <td class="py-3">
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                                                {{ $transaction->status === 'completed' ? 'bg-green-100 text-green-800' : ($transaction->status === 'voided' ? 'bg-red-100 text-red-800' : 'bg-yellow-100 text-yellow-800') }}">
                                                {{ ucfirst($transaction->status) }}
                                            </span>
                                        </td>
                                        <td class="py-3 text-sm text-gray-500">{{ $transaction->created_at->diffForHumans() }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        </div>
    </div>
</x-layouts.app>