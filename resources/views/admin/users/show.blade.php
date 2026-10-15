<x-layouts.app :title="'Detail User'">
    <div class="space-y-6">
        <!-- Header -->
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">{{ $user->name }}</h1>
                <p class="mt-1 text-gray-600">{{ $user->email }}</p>
            </div>
            <div class="flex gap-3">
                <a href="{{ route('admin.users.index') }}"
                   class="px-4 py-2.5 text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors">Kembali</a>
                <a href="{{ route('admin.users.edit', $user) }}"
                   class="px-4 py-2.5 bg-blue-600 text-white font-medium rounded-lg hover:bg-blue-700 transition-colors">Edit</a>
            </div>
        </div>

        <!-- Ringkasan -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-5">
                <p class="text-sm text-gray-500">Role</p>
                <p class="mt-1 text-lg font-semibold text-gray-900">
                    @if ($user->role === 'cashier')
                        Kasir
                    @elseif ($user->role === 'admin')
                        Admin
                    @else
                        Siswa
                    @endif
                </p>
            </div>
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-5">
                <p class="text-sm text-gray-500">Saldo</p>
                <p class="mt-1 text-lg font-semibold text-gray-900 tabular-nums">
                    Rp {{ number_format($user->balance_cents / 100, 0, ',', '.') }}
                </p>
            </div>
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-5">
                <p class="text-sm text-gray-500">Kartu RFID Aktif</p>
                <p class="mt-1 text-lg font-semibold text-gray-900">
                    {{ $user->rfidCards->whereNull('revoked_at')->count() }}
                </p>
            </div>
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-5">
                <p class="text-sm text-gray-500">Device ID</p>
                <p class="mt-1 text-lg font-semibold text-gray-900 font-mono">
                    {{ $user->device_id ?? '-' }}
                </p>
            </div>
        </div>

        <!-- Kartu RFID -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <h2 class="text-lg font-semibold text-gray-900">Kartu RFID</h2>
            @if ($user->rfidCards->isEmpty())
                <p class="mt-3 text-sm text-gray-500">Belum ada kartu RFID assigned.</p>
            @else
                <div class="mt-4 overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-gray-50 border-b border-gray-200">
                            <tr>
                                <th class="px-4 py-2.5 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">UID</th>
                                <th class="px-4 py-2.5 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Label</th>
                                <th class="px-4 py-2.5 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Diterbitkan</th>
                                <th class="px-4 py-2.5 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            @foreach ($user->rfidCards as $card)
                                <tr>
                                    <td class="px-4 py-3 font-mono text-sm text-gray-900">{{ $card->rfid_uid }}</td>
                                    <td class="px-4 py-3 text-sm text-gray-500">{{ $card->label ?? '-' }}</td>
                                    <td class="px-4 py-3 text-sm text-gray-500">{{ $card->created_at->format('d M Y H:i') }}</td>
                                    <td class="px-4 py-3">
                                        @if ($card->revoked_at)
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                                                Dicabut {{ $card->revoked_at->format('d M Y') }}
                                            </span>
                                        @else
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">Aktif</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        <!-- Riwayat transaksi -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200">
                <h2 class="text-lg font-semibold text-gray-900">Riwayat Transaksi</h2>
            </div>

            @if ($transactions->isEmpty())
                <div class="text-center py-12">
                    <p class="text-gray-500">Belum ada transaksi.</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-gray-50 border-b border-gray-200">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Waktu</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Console</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Item</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Total</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            @foreach ($transactions as $tx)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        {{ $tx->created_at->format('d M Y H:i') }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        {{ $tx->console->name ?? '-' }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        @if ($tx->transactionItems->isEmpty())
                                            <span class="text-gray-400">-</span>
                                        @else
                                            {{ $tx->transactionItems->map(fn ($i) => ($i->menuItem->name ?? '-') . ' ×' . $i->quantity)->join(', ') }}
                                        @endif
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        @if ($tx->status === 'completed')
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">Selesai</span>
                                        @elseif ($tx->status === 'awaiting_rfid')
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">Menunggu RFID</span>
                                        @elseif ($tx->status === 'voided')
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">Batal</span>
                                        @elseif ($tx->status === 'pending_sync')
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">Menunggu Sinkron</span>
                                        @else
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">{{ $tx->status }}</span>
                                        @endif
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium tabular-nums text-gray-900">
                                        Rp {{ number_format($tx->total_cents / 100, 0, ',', '.') }}
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
</x-layouts.app>