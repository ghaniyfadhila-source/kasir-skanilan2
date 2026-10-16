<x-layouts.app :title="'Dashboard'" :realtime="$realtime ?? null">
    <div class="space-y-8">
        <!-- Header -->
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">Dashboard</h1>
                <p class="mt-1 text-gray-600">Selamat datang, {{ auth()->user()->name }}. Kelola sistem kasir Skanilan di sini.</p>
            </div>
            <div class="flex items-center gap-3">
                <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-blue-100 text-blue-800">
                    Admin
                </span>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
            <!-- Total Users -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-500">Total Pengguna</p>
                        <p class="mt-2 text-3xl font-bold text-gray-900">{{ $stats['users'] ?? 0 }}</p>
                    </div>
                    <div class="w-12 h-12 rounded-xl bg-blue-100 flex items-center justify-center">
                        <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path>
                        </svg>
                    </div>
                </div>
            </div>

            <!-- Active Consoles -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-500">Console Aktif</p>
                        <p class="mt-2 text-3xl font-bold text-gray-900">{{ $stats['consoles_online'] ?? 0 }}</p>
                    </div>
                    <div class="w-12 h-12 rounded-xl bg-green-100 flex items-center justify-center">
                        <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                        </svg>
                    </div>
                </div>
            </div>

            <!-- Total Menu Items -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-500">Menu Aktif</p>
                        <p class="mt-2 text-3xl font-bold text-gray-900">{{ $stats['menu_items'] ?? 0 }}</p>
                    </div>
                    <div class="w-12 h-12 rounded-xl bg-yellow-100 flex items-center justify-center">
                        <svg class="w-6 h-6 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                        </svg>
                    </div>
                </div>
            </div>

            <!-- Today Transactions -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-500">Transaksi Hari Ini</p>
                        <p class="mt-2 text-3xl font-bold text-gray-900">{{ $stats['transactions_today'] ?? 0 }}</p>
                    </div>
                    <div class="w-12 h-12 rounded-xl bg-purple-100 flex items-center justify-center">
                        <svg class="w-6 h-6 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <h2 class="text-lg font-semibold text-gray-900 mb-4">Aksi Cepat</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <a href="#" class="p-4 border border-gray-200 rounded-lg hover:border-blue-300 hover:bg-blue-50 transition-colors text-center">
                    <svg class="w-8 h-8 mx-auto text-gray-400 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                    </svg>
                    <p class="font-medium text-gray-900">Kelola Console</p>
                    <p class="text-sm text-gray-500 mt-1">Daftar & konfigurasi terminal</p>
                </a>
                <a href="#" class="p-4 border border-gray-200 rounded-lg hover:border-blue-300 hover:bg-blue-50 transition-colors text-center">
                    <svg class="w-8 h-8 mx-auto text-gray-400 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                    </svg>
                    <p class="font-medium text-gray-900">Kelola Menu</p>
                    <p class="text-sm text-gray-500 mt-1">Kategori & item makanan</p>
                </a>
                <a href="#" class="p-4 border border-gray-200 rounded-lg hover:border-blue-300 hover:bg-blue-50 transition-colors text-center">
                    <svg class="w-8 h-8 mx-auto text-gray-400 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path>
                    </svg>
                    <p class="font-medium text-gray-900">Kelola Pengguna</p>
                    <p class="text-sm text-gray-500 mt-1">Siswa, kasir, & RFID</p>
                </a>
                <a href="#" class="p-4 border border-gray-200 rounded-lg hover:border-blue-300 hover:bg-blue-50 transition-colors text-center">
                    <svg class="w-8 h-8 mx-auto text-gray-400 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                    </svg>
                    <p class="font-medium text-gray-900">Lihat Laporan</p>
                    <p class="text-sm text-gray-500 mt-1">Transaksi & rekap harian</p>
                </a>
            </div>
        </div>
<!-- Recent Activity (Ticket 09: diisi langsung lewat Realtime) -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6"
             x-data="adminActivity()" x-init="init()">

            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-semibold text-gray-900">Aktivitas Terbaru</h2>
                <div class="flex items-center gap-3">
                    <span class="flex items-center gap-1.5 text-xs"
                          :class="connected ? 'text-green-600' : 'text-gray-400'"
                          :title="connected ? 'Terhubung ke Realtime' : 'Belum terhubung'">
                        <span class="w-2 h-2 rounded-full" :class="connected ? 'bg-green-500' : 'bg-gray-300'"></span>
                        <span x-text="connected ? 'Live' : 'Terputus'"></span>
                    </span>
                    <a href="{{ route('admin.reports.index') }}" class="text-sm text-blue-600 hover:underline">Lihat semua</a>
                </div>
            </div>

            {{-- Daftar transaksi yang masuk lewat event Realtime. --}}
            <div x-show="items.length > 0" class="divide-y divide-gray-100">
                <template x-for="item in items" :key="item.transaction_id">
                    <div class="flex items-center justify-between py-3">
                        <div class="flex items-center gap-3 min-w-0">
                            <span class="w-8 h-8 rounded-full flex items-center justify-center flex-shrink-0"
                                  :class="item.kind === 'void' ? 'bg-red-100 text-red-600'
                                          : (item.kind === 'pending' ? 'bg-amber-100 text-amber-600' : 'bg-green-100 text-green-600')">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                          :d="item.kind === 'completed'
                                              ? 'M5 13l4 4L19 7'
                                              : (item.kind === 'pending' ? 'M12 8v4m0 4h.01' : 'M6 18L18 6M6 6l12 12')"></path>
                                </svg>
                            </span>
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-gray-900 truncate" x-text="item.title"></p>
                                <p class="text-xs text-gray-500" x-text="item.detail"></p>
                            </div>
                        </div>
                        <div class="text-right flex-shrink-0 ml-3">
                            <p class="text-sm font-semibold text-gray-900" x-text="item.amount"></p>
                            <p class="text-xs text-gray-400" x-text="item.time"></p>
                        </div>
                    </div>
                </template>
            </div>

            <div x-show="items.length === 0" class="text-center py-8 text-gray-500">
                <svg class="w-12 h-12 mx-auto text-gray-300 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1.707.293.707.707V19a2 2 0 01-2 2z"></path>
                </svg>
                <p class="text-lg font-medium">Belum ada aktivitas</p>
                <p class="mt-1">Transaksi akan muncul di sini secara langsung</p>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('alpine:init', () => {
            /*
             * Feed aktivitas dashboard admin (Ticket 09).
             *
             * Backend menyiarkan `transaction.created` setiap pembayaran
             * berhasil, `order.placed` saat order menunggu tap,
             * `order.voided` saat dibatalkan, dan `console.status` saat
             * heartbeat konsol diterima.
             *
             * Daftar dibatasi supaya tidak tumbuh tanpa batas selama admin
             * membukanya seharian.
             */
            Alpine.data('adminActivity', () => ({
                items: [],
                connected: false,
                maxItems: 25,

                init() {
                    if (!window.KasirRealtime) return;

                    const topic = @json($realtime['topics']['adminDashboard'] ?? null);

                    if (!topic) return;

                    window.KasirRealtime.subscribe(topic, {
                        'transaction.created': (payload) => {
                            this.push({
                                kind: 'completed',
                                key: payload.transaction_id,
                                title: (payload.buyer_name || 'Pembeli') + ' - ' + (payload.receipt_no || 'struk'),
                                detail: payload.device_id || '',
                                amount: payload.total_formatted || '',
                                time: this.formatTime(payload.completed_at),
                            });
                        },

                        'order.placed': (payload) => {
                            this.push({
                                kind: 'pending',
                                key: payload.order_id,
                                title: 'Order menunggu tap - ' + (payload.total_formatted || ''),
                                detail: payload.device_id || '',
                                amount: '',
                                time: this.formatTime(new Date().toISOString()),
                            });
                        },

                        'order.voided': (payload) => {
                            this.push({
                                kind: 'void',
                                key: payload.order_id,
                                title: 'Order dibatalkan',
                                detail: payload.reason || '',
                                amount: '',
                                time: this.formatTime(new Date().toISOString()),
                            });
                        },

                        'console.status': (payload) => {
                            this.push({
                                kind: 'void',
                                key: 'konsol-' + payload.device_id + '-' + Date.now(),
                                title: 'Konsol ' + payload.device_id + ' - ' + payload.status,
                                detail: payload.name || '',
                                amount: '',
                                time: this.formatTime(payload.last_seen_at),
                            });
                        },
                    });

                    this.connected = true;
                },

                push(item) {
                    // Order yang berubah status (menunggu -> dibayar) menggantikan
                    // baris lamanya, bukan menambah baris baru.
                    this.items = this.items.filter((existing) => existing.key !== item.key);
                    this.items.unshift(item);

                    if (this.items.length > this.maxItems) {
                        this.items = this.items.slice(0, this.maxItems);
                    }
                },

                formatTime(value) {
                    if (!value) return '';

                    const date = new Date(value);

                    return Number.isNaN(date.getTime())
                        ? ''
                        : date.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' });
                },
            }));
        });
    </script>
</x-layouts.app>