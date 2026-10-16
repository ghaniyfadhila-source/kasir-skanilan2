<x-layouts.cashier :realtime="$realtime ?? null" :title="'Menunggu Tap RFID - Kasir Skanilan'">
    <div x-data="awaitingApp()" x-init="init()">
        <div class="min-h-screen flex flex-col items-center justify-center p-4 bg-gradient-to-b from-blue-50 to-white">
            <!-- Header -->
            <div class="w-full max-w-md text-center mb-8">
                <div class="w-20 h-20 mx-auto mb-4 bg-blue-100 rounded-2xl flex items-center justify-center">
                    <svg class="w-10 h-10 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"></path>
                    </svg>
                </div>
                <h1 class="text-2xl font-bold text-gray-900">Menunggu Tap RFID</h1>
                <p class="text-gray-500 mt-1">Silakan pelanggan tap kartu RFID ke console</p>
            </div>

            <!-- Order Summary Card -->
            <div class="w-full max-w-md bg-white rounded-2xl shadow-xl p-6 mb-6 border border-gray-100">
                <div class="flex items-center justify-between mb-4">
                    <span class="text-sm text-gray-500">No. Pesanan</span>
                    <span class="font-mono text-sm text-gray-700" x-text="shortOrderId"></span>
                </div>
                
                <div class="space-y-3 mb-4">
                    <template x-for="item in orderItems" :key="item.menu_item_id">
                        <div class="flex items-center justify-between py-2 border-b border-gray-100 last:border-0">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 bg-gray-50 rounded-lg flex items-center justify-center">
                                    <template x-if="item.image_url">
                                        <img :src="item.image_url" class="w-8 h-8 object-cover rounded">
                                    </template>
                                    <template x-if="!item.image_url">
                                        <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                                    </template>
                                </div>
                                <div>
                                    <p class="font-medium text-gray-900" x-text="item.name"></p>
                                    <p class="text-sm text-gray-500" x-text="'Qty: ' + item.quantity"></p>
                                </div>
                            </div>
                            <span class="font-medium text-gray-900" x-text="formatPrice(item.subtotal_cents)"></span>
                        </div>
                    </template>
                </div>

                <div class="flex items-center justify-between pt-4 border-t border-gray-200 text-lg">
                    <span class="font-semibold text-gray-900">Total</span>
                    <span class="font-bold text-blue-600 text-xl" x-text="formatPrice(totalCents)"></span>
                </div>
            </div>

            <!-- Countdown Timer -->
            <div class="w-full max-w-md mb-6">
                <div class="bg-white rounded-2xl shadow-xl p-6 border border-gray-100 text-center">
                    <div class="mb-2">
                        <span class="text-sm text-gray-500">Waktu tersisa</span>
                    </div>
                    <div class="text-5xl font-mono font-bold text-blue-600 tabular-nums" x-text="formatTime(timeLeft)" x-transition></div>
                    <div class="mt-4 h-2 bg-gray-100 rounded-full overflow-hidden">
                        <div class="h-full bg-blue-600 transition-all duration-1000 ease-linear" :style="'width: ' + progressPercent + '%'"></div>
                    </div>
                    <p class="mt-3 text-sm text-gray-500">Pesanan akan dibatalkan otomatis setelah waktu habis</p>
                </div>
            </div>

            <!-- Cancel Button -->
            <button @click="cancelOrder" :disabled="cancelLoading" class="w-full max-w-md px-6 py-3 bg-white text-red-600 font-semibold rounded-xl border-2 border-red-200 hover:bg-red-50 hover:border-red-300 transition-colors disabled:opacity-50 disabled:cursor-not-allowed">
                <span x-show="!cancelLoading" class="flex items-center justify-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                    Batalkan Pesanan
                </span>
                <span x-show="cancelLoading" class="flex items-center justify-center gap-2">
                    <svg class="animate-spin h-5 w-5" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                    Membatalkan...
                </span>
            </button>

            <!-- Status Messages -->
            <div x-show="statusMessage" class="mt-6 w-full max-w-md text-center" x-transition>
                <div class="p-4 rounded-xl" :class="statusType === 'error' ? 'bg-red-50 text-red-700 border border-red-200' : 'bg-blue-50 text-blue-700 border border-blue-200'">
                    <p x-text="statusMessage"></p>
                </div>
            </div>

            <!-- Offline Indicator -->
            <div x-show="!isOnline" class="fixed bottom-4 left-1/2 -translate-x-1/2 z-50 bg-red-600 text-white px-4 py-2 rounded-lg shadow-lg text-sm font-medium flex items-center gap-2" x-transition>
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 5.636l-3.536 3.536m0 5.656l3.536 3.536M9.172 9.172L5.636 5.636m3.536 9.192l-3.536 3.536M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-5 0a4 4 0 11-8 0 4 4 0 018 0z"></path></svg>
                Offline - Menunggu koneksi...
            </div>
        </div>
    </div>

    <script>
        function awaitingApp() {
            return {
                orderId: '',
                orderItems: [],
                totalCents: 0,
                timeLeft: 60,
                timer: null,
                statusMessage: '',
                statusType: 'info',
                cancelLoading: false,
                isOnline: navigator.onLine,
                realtimeActive: false,
                pollTimer: null,

                init() {
                    // Get orderId from URL
                    const path = window.location.pathname;
                    this.orderId = path.split('/').pop();
                    
                    if (!this.orderId) {
                        this.statusMessage = 'ID pesanan tidak valid';
                        this.statusType = 'error';
                        return;
                    }

                    // Start countdown
                    this.startCountdown();

                    // Poll for status (fallback for Realtime)
                    this.pollStatus();

                    // Listen for online/offline
                    window.addEventListener('online', () => this.isOnline = true);
                    window.addEventListener('offline', () => this.isOnline = false);

                    // Initialize Supabase Realtime
                    this.initRealtime();
                },

                get shortOrderId() {
                    return this.orderId.slice(0, 8).toUpperCase();
                },

                formatPrice(cents) {
                    return 'Rp ' + (cents / 100).toLocaleString('id-ID');
                },

                formatTime(seconds) {
                    const m = Math.floor(seconds / 60).toString().padStart(2, '0');
                    const s = (seconds % 60).toString().padStart(2, '0');
                    return `${m}:${s}`;
                },

                get progressPercent() {
                    return (this.timeLeft / 60) * 100;
                },

                startCountdown() {
                    this.timer = setInterval(() => {
                        if (this.timeLeft > 0) {
                            this.timeLeft--;
                        } else {
                            this.onTimeout();
                        }
                    }, 1000);
                },

                async onTimeout() {
                    this.clearAllTimers();
                    this.statusMessage = 'Waktu habis - Pesanan dibatalkan';
                    this.statusType = 'error';
                    
                    // Try to cancel on server
                    try {
                        await fetch(`/api/cashier/orders/${this.orderId}/cancel`, {
                            method: 'POST',
                            headers: {
                                'Authorization': `Bearer ${localStorage.getItem('cashier_token')}`,
                                'Accept': 'application/json',
                            }
                        });
                    } catch (e) {
                        console.error('Auto-cancel failed:', e);
                    }

                    setTimeout(() => {
                        window.location.href = '{{ route("cashier.menu") }}';
                    }, 3000);
                },

                async pollStatus() {
                    const poll = async () => {
                        if (this.timeLeft <= 0) return;
                        
                        try {
                            const response = await fetch(`/api/cashier/orders/${this.orderId}/status`, {
                                headers: {
                                    'Authorization': `Bearer ${localStorage.getItem('cashier_token')}`,
                                    'Accept': 'application/json',
                                }
                            });
                            
                            const data = await response.json();
                            
                            if (data.success && data.data) {
                                const status = data.data.status;
                                
                                if (status === 'completed') {
                                    this.clearAllTimers();
                                    window.location.href = `{{ route('cashier.success', ':id') }}`.replace(':id', this.orderId);
                                    return;
                                } else if (status === 'voided') {
                                    this.clearAllTimers();
                                    this.statusMessage = 'Pesanan dibatalkan';
                                    this.statusType = 'error';
                                    setTimeout(() => {
                                        window.location.href = '{{ route("cashier.menu") }}';
                                    }, 2000);
                                    return;
                                }
                                
                                // Update order details if not loaded yet
                                if (this.orderItems.length === 0 && data.data.items) {
                                    this.orderItems = data.data.items;
                                    this.totalCents = data.data.total_cents;
                                }
                            }
                        } catch (e) {
                            console.error('Poll error:', e);
                        }
                    };

                    // Poll setiap 2 detik sebagai cadangan Realtime.
                    //
                    // Poll berhenti sendiri setelah Realtime memberi tahu
                    // statusnya, supaya tidak ada request sia-sia selama
                    // koneksi WebSocket sehat.
                    this.pollTimer = setInterval(poll, 2000);
                    poll();
                },

                async cancelOrder() {
                    this.cancelLoading = true;
                    try {
                        const response = await fetch(`/api/cashier/orders/${this.orderId}/cancel`, {
                            method: 'POST',
                            headers: {
                                'Authorization': `Bearer ${localStorage.getItem('cashier_token')}`,
                                'Accept': 'application/json',
                            }
                        });

                        const data = await response.json();

                        if (data.success) {
                            this.clearAllTimers();
                            this.statusMessage = 'Pesanan dibatalkan';
                            this.statusType = 'info';
                            setTimeout(() => {
                                window.location.href = '{{ route("cashier.menu") }}';
                            }, 1500);
                        } else {
                            this.toast(data.message || 'Gagal membatalkan', 'error');
                        }
                    } catch (e) {
                        this.toast('Terjadi kesalahan', 'error');
                    } finally {
                        this.cancelLoading = false;
                    }
                },

                initRealtime() {
                    // Ticket 09: bayar outras IGNOREd. Poll tetap jalan sebagai
                    // cadangan - kalau WebSocket putus, halaman harus tetap
                    // bisa tahu pembayaran berhasil.
                    if (!window.KasirRealtime) return;

                    const topic = @json($realtime['topics']['cashier'] ?? null);

                    if (!topic) return;

                    this.realtimeActive = true;

                    window.KasirRealtime.subscribe(topic, {
                        'completed': (payload) => {
                            // Hanya order milik halaman ini; channel kasir juga
                            // menerima order dari halaman kasir lain.
                            if (payload.transaction_id !== this.orderId) return;

                            this.clearAllTimers();
                            this.statusMessage = 'Pembayaran berhasil';
                            this.statusType = 'success';

                            setTimeout(() => {
                                window.location.href = @json(route('cashier.success', ':id')).replace(':id', payload.transaction_id);
                            }, 900);
                        },

                        'void': (payload) => {
                            if (payload.order_id !== this.orderId) return;

                            this.clearAllTimers();
                            this.statusMessage = payload.reason === 'kedaluwarsa'
                                ? 'Waktu habis - Pesanan dibatalkan'
                                : 'Pesanan dibatalkan';
                            this.statusType = 'error';

                            setTimeout(() => {
                                window.location.href = @json(route('cashier.menu'));
                            }, 2000);
                        },
                    });
                },

                /**
                 * Matikan semua timer halaman ini.
                 *
                 * Tanpa ini, navigasi karena Realtime akan meninggalkan
                 * interval countdown dan polling yang masih berjalan di
                 * background, lalu mulai membuat request untuk order yang
                 * sudah selesai.
                 */
                clearAllTimers() {
                    if (this.timer) clearInterval(this.timer);
                    if (this.pollTimer) clearInterval(this.pollTimer);

                    this.timer = null;
                    this.pollTimer = null;
                    this.realtimeActive = true;
                },

                toast(message, type = 'success') {
                    const container = document.querySelector('[x-data="toastContainer()"]');
                    if (container && container.__x) {
                        container.__x.$data.add(message, type);
                    }
                }
            }
        }

        function toastContainer() {
            return {
                toasts: [],
                add(message, type = 'info') {
                    const id = Date.now();
                    this.toasts.push({ id, message, type });
                    setTimeout(() => this.remove(id), 4000);
                },
                remove(id) {
                    this.toasts = this.toasts.filter(t => t.id !== id);
                }
            }
        }
    </script>
</x-layouts.cashier>