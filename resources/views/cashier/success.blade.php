<x-layouts.cashier :title="'Transaksi Berhasil - Kasir Skanilan'">
    <div x-data="successApp()" x-init="init()">
        <div class="min-h-screen flex flex-col items-center justify-center p-4 bg-gradient-to-b from-green-50 to-white">
            <!-- Success Animation -->
            <div class="text-center mb-8">
                <div class="w-24 h-24 mx-auto mb-4 bg-green-100 rounded-full flex items-center justify-center">
                    <svg class="w-12 h-12 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                    </svg>
                </div>
                <h1 class="text-3xl font-bold text-green-700">Transaksi Berhasil!</h1>
                <p class="text-gray-500 mt-1">Pembayaran telah diproses</p>
            </div>

            <!-- Receipt Card -->
            <div class="w-full max-w-md bg-white rounded-2xl shadow-xl p-6 mb-6 border border-gray-100">
                <!-- Receipt Header -->
                <div class="text-center mb-6 pb-4 border-b border-gray-200">
                    <div class="w-12 h-12 mx-auto mb-2 bg-blue-100 rounded-xl flex items-center justify-center">
                        <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"></path>
                        </svg>
                    </div>
                    <h2 class="text-lg font-semibold text-gray-900">Kasir Skanilan</h2>
                    <p class="text-sm text-gray-500" x-text="consoleName"></p>
                </div>

                <!-- Receipt Details -->
                <div class="space-y-3 mb-4">
                    <div class="flex justify-between text-sm">
                        <span class="text-gray-500">No. Struk</span>
                        <span class="font-mono font-medium text-gray-900" x-text="receiptNo"></span>
                    </div>
                    <div class="flex justify-between text-sm">
                        <span class="text-gray-500">Waktu</span>
                        <span class="text-gray-900" x-text="formattedTime"></span>
                    </div>
                    <div class="flex justify-between text-sm">
                        <span class="text-gray-500">Kasir</span>
                        <span class="text-gray-900" x-text="cashierName"></span>
                    </div>
                    <div class="flex justify-between text-sm">
                        <span class="text-gray-500">Pembeli</span>
                        <span class="text-gray-900 font-medium" x-text="buyerName"></span>
                    </div>
                </div>

                <!-- Items -->
                <div class="border-t border-gray-200 pt-4 mb-4">
                    <template x-for="item in items" :key="item.menu_item_id">
                        <div class="flex justify-between py-2 border-b border-gray-100 last:border-0">
                            <div>
                                <p class="font-medium text-gray-900" x-text="item.name"></p>
                                <p class="text-sm text-gray-500" x-text="item.quantity + ' x ' + formatPrice(item.unit_price_cents)"></p>
                            </div>
                            <span class="font-medium text-gray-900" x-text="formatPrice(item.subtotal_cents)"></span>
                        </div>
                    </template>
                </div>

                <!-- Total -->
                <div class="flex justify-between text-lg font-bold pt-4 border-t border-gray-200">
                    <span class="text-gray-900">Total</span>
                    <span class="text-blue-600" x-text="formatPrice(totalCents)"></span>
                </div>

                <!-- New Balance -->
                <div class="mt-4 p-4 bg-green-50 rounded-xl border border-green-200">
                    <div class="flex justify-between">
                        <span class="text-green-700 font-medium">Saldo Baru Pembeli</span>
                        <span class="text-green-700 font-bold text-lg" x-text="formatPrice(newBalanceCents)"></span>
                    </div>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="w-full max-w-md space-y-3">
                <button @click="newTransaction" class="w-full py-3 bg-blue-600 text-white font-semibold rounded-xl hover:bg-blue-700 transition-colors">
                    Transaksi Baru
                </button>
                <button @click="printReceipt" class="w-full py-3 bg-white text-gray-700 font-semibold rounded-xl border-2 border-gray-200 hover:bg-gray-50 transition-colors flex items-center justify-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path></svg>
                    Cetak Struk
                </button>
            </div>
        </div>
    </div>

    <script>
        function successApp() {
            return {
                orderId: '',
                receiptNo: '',
                items: [],
                totalCents: 0,
                newBalanceCents: 0,
                buyerName: '',
                cashierName: '',
                consoleName: '',
                formattedTime: '',

                init() {
                    const path = window.location.pathname;
                    this.orderId = path.split('/').pop();
                    
                    // Load from localStorage (passed from awaiting page)
                    const receiptData = JSON.parse(localStorage.getItem('cashier_last_receipt') || '{}');
                    
                    if (receiptData.orderId === this.orderId) {
                        this.receiptNo = receiptData.receipt_no || '';
                        this.items = receiptData.items || [];
                        this.totalCents = receiptData.total_cents || 0;
                        this.newBalanceCents = receiptData.new_balance_cents || 0;
                        this.buyerName = receiptData.buyer_name || '';
                        this.formattedTime = receiptData.formatted_time || '';
                    } else {
                        // Fallback: fetch from API
                        this.fetchReceipt();
                    }

                    this.cashierName = localStorage.getItem('cashier_name') || '';
                    this.consoleName = localStorage.getItem('cashier_console_name') || '';
                },

                async fetchReceipt() {
                    try {
                        const token = localStorage.getItem('cashier_token');
                        const response = await fetch(`/api/cashier/orders/${this.orderId}/status`, {
                            headers: {
                                'Authorization': `Bearer ${token}`,
                                'Accept': 'application/json',
                            }
                        });
                        const data = await response.json();
                        
                        if (data.success && data.data) {
                            this.receiptNo = data.data.receipt_no || '';
                            this.items = data.data.items || [];
                            this.totalCents = data.data.total_cents || 0;
                            this.newBalanceCents = data.data.new_balance_cents || 0;
                            this.buyerName = data.data.buyer_name || '';
                            this.formattedTime = data.data.completed_at 
                                ? new Date(data.data.completed_at).toLocaleString('id-ID')
                                : new Date().toLocaleString('id-ID');
                        }
                    } catch (e) {
                        console.error('Failed to fetch receipt:', e);
                    }
                },

                formatPrice(cents) {
                    return 'Rp ' + (cents / 100).toLocaleString('id-ID');
                },

                newTransaction() {
                    // Clear receipt data
                    localStorage.removeItem('cashier_last_receipt');
                    window.location.href = '{{ route("cashier.menu") }}';
                },

                printReceipt() {
                    window.print();
                }
            }
        }
    </script>

    <!-- Print Styles -->
    <style media="print">
        @media print {
            header, .fixed, button, .no-print {
                display: none !important;
            }
            body {
                background: white !important;
            }
            .max-w-md {
                max-width: 100% !important;
                box-shadow: none !important;
                border: none !important;
            }
        }
    </style>
</x-layouts.cashier>