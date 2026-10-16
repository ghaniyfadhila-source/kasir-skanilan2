<x-layouts.cashier :realtime="$realtime ?? null" :title="'Menu Kasir - ' . (session('console_name') ?? 'Kantin')">
    <div x-data="cashierApp()" x-init="init()">
        <!-- Header -->
        <header class="sticky top-0 z-30 bg-white border-b border-gray-200 shadow-sm">
            <div class="flex items-center justify-between h-16 px-4 sm:px-6">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-blue-100 rounded-xl flex items-center justify-center">
                        <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"></path>
                        </svg>
                    </div>
                    <div>
                        <h1 class="text-lg font-bold text-gray-900">Kasir Skanilan</h1>
                        <p class="text-xs text-gray-500" x-text="consoleName"></p>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <span class="text-sm text-gray-600" x-text="cashierName"></span>
                    <button @click="logout" class="text-sm text-red-600 hover:text-red-800 font-medium">Keluar</button>
                </div>
            </div>
        </header>

        <!-- Main Content -->
        <main class="pb-24">
            <!-- Cart Sidebar (Mobile) -->
            <div x-show="showCart" x-transition:enter="transition ease-in-out duration-300" x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0" x-transition:leave="transition ease-in-out duration-300" x-transition:leave-start="translate-x-0" x-transition:leave-end="translate-x-full" class="fixed inset-0 z-40 lg:hidden" @click="showCart = false">
                <div class="absolute right-0 top-0 h-full w-full max-w-sm bg-white shadow-xl" @click.outside>
                    <div class="flex items-center justify-between p-4 border-b border-gray-200 sticky top-0 bg-white">
                        <h2 class="text-lg font-semibold">Keranjang (<span x-text="cartTotalItems"></span>)</h2>
                        <button @click="showCart = false" class="text-gray-500 hover:text-gray-700">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                        </button>
                    </div>
                    <div class="overflow-y-auto flex-1 p-4" style="max-height: calc(100vh - 120px)">
                        <template x-for="item in cart" :key="item.menu_item_id + '-' + item.variant">
                            <div class="flex items-center gap-3 p-3 bg-gray-50 rounded-xl mb-3">
                                <template x-if="item.image_url">
                                    <img :src="item.image_url" class="w-16 h-16 object-cover rounded-lg">
                                </template>
                                <template x-if="!item.image_url">
                                    <div class="w-16 h-16 bg-gray-100 rounded-lg flex items-center justify-center">
                                        <svg class="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                                    </div>
                                </template>
                                <div class="flex-1 min-w-0">
                                    <p class="font-medium text-gray-900 truncate" x-text="item.name"></p>
                                    <p class="text-sm text-gray-500" x-text="formatPrice(item.price_cents)"></p>
                                    <div class="flex items-center gap-2 mt-1">
                                        <button @click="updateQty(item, item.quantity - 1)" :disabled="item.quantity <= 1" class="w-8 h-8 rounded-lg border border-gray-300 flex items-center justify-center text-gray-600 hover:bg-gray-100 disabled:opacity-50">-</button>
                                        <span class="w-10 text-center font-medium" x-text="item.quantity"></span>
                                        <button @click="updateQty(item, item.quantity + 1)" class="w-8 h-8 rounded-lg border border-gray-300 flex items-center justify-center text-gray-600 hover:bg-gray-100">+</button>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <p class="font-semibold text-gray-900" x-text="formatPrice(item.price_cents * item.quantity)"></p>
                                    <button @click="removeItem(item)" class="text-xs text-red-600 hover:text-red-800 mt-1">Hapus</button>
                                </div>
                            </div>
                        </template>
                        <div x-show="cart.length === 0" class="text-center py-12 text-gray-500">
                            <svg class="w-16 h-16 mx-auto mb-3 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"></path></svg>
                            <p>Keranjang kosong</p>
                        </div>
                    </div>
                    <div class="p-4 border-t border-gray-200 bg-white sticky bottom-0">
                        <div class="flex justify-between text-lg font-semibold mb-3">
                            <span>Total</span>
                            <span x-text="formatPrice(cartTotal)"></span>
                        </div>
                        <button @click="showCart = false; checkout()" :disabled="cart.length === 0 || checkoutLoading" class="w-full py-3 bg-blue-600 text-white font-semibold rounded-xl hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed">
                            <span x-show="!checkoutLoading">BAYAR - <span x-text="formatPrice(cartTotal)"></span></span>
                            <span x-show="checkoutLoading" class="flex items-center justify-center gap-2"><svg class="animate-spin h-5 w-5" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>Memproses...</span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Categories Tabs -->
            <div class="sticky top-16 z-20 bg-white border-b border-gray-200 px-4" x-show="categories.length > 0">
                <div class="overflow-x-auto pb-2" style="-webkit-overflow-scrolling: touch">
                    <div class="flex gap-2 min-w-max" style="min-width: max-content">
                        <template x-for="cat in categories" :key="cat.id">
                            <button @click="activeCategory = cat.id" 
                                :class="activeCategory === cat.id ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'"
                                class="px-4 py-2 rounded-full text-sm font-medium whitespace-nowrap transition-colors">
                                <span x-text="cat.name"></span>
                                <span class="ml-2 px-2 py-0.5 text-xs rounded-full" :class="activeCategory === cat.id ? 'bg-blue-200' : 'bg-gray-200'" x-text="cat.items.length"></span>
                            </button>
                        </template>
                    </div>
                </div>
            </div>

            <!-- Menu Grid -->
            <div class="p-4">
                <template x-if="loading">
                    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4">
                        <template x-for="i in 8" :key="i">
                            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 animate-pulse">
                                <div class="w-full h-32 bg-gray-200 rounded-lg mb-3"></div>
                                <div class="h-4 bg-gray-200 rounded w-3/4 mb-2"></div>
                                <div class="h-4 bg-gray-200 rounded w-1/2"></div>
                            </div>
                        </template>
                    </div>
                </template>

                <template x-if="!loading">
                    <div x-show="getCurrentCategoryItems().length > 0" class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4">
                        <template x-for="item in getCurrentCategoryItems()" :key="item.id">
                            <button @click="addToCart(item)" class="bg-white rounded-xl shadow-sm border border-gray-200 p-3 hover:border-blue-300 hover:shadow-md transition-all group w-full text-left">
                                <div class="relative aspect-square mb-3 rounded-lg overflow-hidden bg-gray-50">
                                    <template x-if="item.image_url">
                                        <img :src="item.image_url" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-200">
                                    </template>
                                    <template x-if="!item.image_url">
                                        <div class="w-full h-full flex items-center justify-center">
                                            <svg class="w-12 h-12 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                                        </div>
                                    </template>
                                    <template x-if="item.stock !== -1 && item.stock <= 5 && item.stock > 0">
                                        <span class="absolute top-2 right-2 bg-yellow-500 text-white text-xs px-2 py-1 rounded-full">Sisa: <span x-text="item.stock"></span></span>
                                    </template>
                                    <template x-if="item.stock === 0">
                                        <span class="absolute top-2 right-2 bg-red-500 text-white text-xs px-2 py-1 rounded-full">Habis</span>
                                    </template>
                                </div>
                                <h3 class="font-medium text-gray-900 mb-1 line-clamp-1" x-text="item.name"></h3>
                                <p class="text-lg font-bold text-blue-600" x-text="formatPrice(item.price_cents)"></p>
                                <p class="text-xs text-gray-500 mt-1" x-text="item.is_unlimited ? 'Stok: Unlimited' : 'Stok: ' + item.stock"></p>
                            </button>
                        </template>
                    </div>

                    <div x-show="getCurrentCategoryItems().length === 0" class="text-center py-12">
                        <svg class="w-16 h-16 mx-auto text-gray-300 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path></svg>
                        <p class="text-gray-500">Tidak ada item di kategori ini</p>
                    </div>
                </template>
            </div>
        </main>

        <!-- Cart Button (Mobile FAB) -->
        <button @click="showCart = true" x-show="cart.length > 0" class="fixed bottom-6 right-6 z-30 lg:hidden flex items-center gap-2 px-5 py-3 bg-blue-600 text-white rounded-full shadow-xl">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"></path></svg>
            <span class="font-medium" x-text="cartTotalItems"></span>
            <span class="bg-blue-700 px-2 py-0.5 rounded-full text-xs font-bold" x-text="formatPrice(cartTotal)"></span>
        </button>

        <!-- Empty Cart Hint -->
        <div x-show="cart.length === 0" class="fixed bottom-6 right-6 z-20 lg:hidden bg-white rounded-xl shadow-xl p-4 border border-gray-200">
            <p class="text-sm text-gray-500">Pilih menu untuk memulai pesanan</p>
        </div>
    </div>

    <script>
        function cashierApp() {
            return {
                // State
                categories: [],
                activeCategory: null,
                cart: [],
                showCart: false,
                loading: true,
                checkoutLoading: false,
                consoleName: '',
                cashierName: '',
                deviceId: '',
                realtimeChannel: null,
                supabaseClient: null,

                async init() {
                    // Load from localStorage
                    this.consoleName = localStorage.getItem('cashier_console_name') || '';
                    this.cashierName = localStorage.getItem('cashier_name') || '';
                    this.deviceId = localStorage.getItem('cashier_device_id') || '';
                    this.cart = JSON.parse(localStorage.getItem('cashier_cart') || '[]');

                    // Load menu
                    await this.loadMenu();

                    // Initialize Supabase Realtime
                    this.initRealtime();
                },

                async loadMenu() {
                    try {
                        const response = await this.apiGet('/api/cashier/menu');
                        if (response.success) {
                            this.categories = response.data;
                            this.activeCategory = this.categories[0]?.id || null;
                        }
                    } catch (e) {
                        console.error('Failed to load menu:', e);
                        this.toast('Gagal memuat menu', 'error');
                    } finally {
                        this.loading = false;
                    }
                },

                async apiGet(url) {
                    const token = localStorage.getItem('cashier_token');
                    const response = await fetch(url, {
                        headers: {
                            'Authorization': `Bearer ${token}`,
                            'Accept': 'application/json',
                        }
                    });
                    return response.json();
                },

                async apiPost(url, data) {
                    const token = localStorage.getItem('cashier_token');
                    const response = await fetch(url, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Authorization': `Bearer ${token}`,
                            'Accept': 'application/json',
                        },
                        body: JSON.stringify(data)
                    });
                    return response.json();
                },

                // Cart methods
                addToCart(item) {
                    if (item.stock === 0) {
                        this.toast('Item habis', 'error');
                        return;
                    }
                    
                    const existing = this.cart.find(i => i.menu_item_id === item.id);
                    if (existing) {
                        if (item.stock !== -1 && existing.quantity >= item.stock) {
                            this.toast('Stok tidak mencukupi', 'error');
                            return;
                        }
                        existing.quantity++;
                    } else {
                        this.cart.push({
                            menu_item_id: item.id,
                            name: item.name,
                            price_cents: item.price_cents,
                            quantity: 1,
                            image_url: item.image_url,
                            stock: item.stock,
                            is_unlimited: item.is_unlimited,
                        });
                    }
                    this.saveCart();
                    this.showCart = true;
                    this.toast('Ditambahkan ke keranjang');
                },

                updateQty(item, newQty) {
                    if (newQty < 1) return this.removeItem(item);
                    if (!item.is_unlimited && newQty > item.stock) {
                        this.toast('Stok tidak mencukupi', 'error');
                        return;
                    }
                    item.quantity = newQty;
                    this.saveCart();
                },

                removeItem(item) {
                    this.cart = this.cart.filter(i => i.menu_item_id !== item.menu_item_id);
                    this.saveCart();
                    if (this.cart.length === 0) this.showCart = false;
                },

                saveCart() {
                    localStorage.setItem('cashier_cart', JSON.stringify(this.cart));
                },

                get cartTotalItems() {
                    return this.cart.reduce((sum, item) => sum + item.quantity, 0);
                },

                get cartTotal() {
                    return this.cart.reduce((sum, item) => sum + (item.price_cents * item.quantity), 0);
                },

                getCurrentCategoryItems() {
                    const cat = this.categories.find(c => c.id === this.activeCategory);
                    return cat?.items || [];
                },

                formatPrice(cents) {
                    return 'Rp ' + (cents / 100).toLocaleString('id-ID');
                },

                async checkout() {
                    if (this.cart.length === 0) return;
                    this.checkoutLoading = true;

                    const items = this.cart.map(item => ({
                        menu_item_id: item.menu_item_id,
                        quantity: item.quantity,
                    }));

                    const idempotencyKey = this.generateIdempotencyKey();

                    try {
                        const response = await this.apiPost('/api/cashier/orders', {
                            items,
                            idempotency_key: idempotencyKey,
                        });

                        if (response.success) {
                            // Clear cart
                            this.cart = [];
                            this.saveCart();
                            this.showCart = false;

                            // Navigate to awaiting page
                            window.location.href = `{{ route('cashier.awaiting', ':id') }}`.replace(':id', response.data.order_id);
                        } else {
                            this.toast(response.message || 'Gagal membuat pesanan', 'error');
                        }
                    } catch (e) {
                        console.error(e);
                        this.toast('Terjadi kesalahan', 'error');
                    } finally {
                        this.checkoutLoading = false;
                    }
                },

                generateIdempotencyKey() {
                    return `${this.deviceId}-${Date.now()}-${Math.random().toString(36).substr(2, 4)}`;
                },

                initRealtime() {
                    // Supabase Realtime will be initialized here when available
                    // For now, we'll use polling fallback
                    console.log('Realtime init - will connect to cashier:' + this.deviceId);
                },

                logout() {
                    localStorage.removeItem('cashier_token');
                    localStorage.removeItem('cashier_device_id');
                    localStorage.removeItem('cashier_console_name');
                    localStorage.removeItem('cashier_name');
                    localStorage.removeItem('cashier_cart');
                    window.location.href = '{{ route("cashier.login") }}';
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

    <!-- Toast Styles -->
    <style>
        [x-data="toastContainer()"] .toast {
            animation: slideIn 0.3s ease-out;
        }
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
    </style>
</x-layouts.cashier>