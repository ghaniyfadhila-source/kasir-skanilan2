<x-layouts.cashier :title="'Login Kasir'">
    <div class="min-h-screen flex items-center justify-center p-4 bg-gray-100">
        <div class="w-full max-w-md bg-white rounded-2xl shadow-xl p-8">
            <!-- Logo/Header -->
            <div class="text-center mb-8">
                <div class="w-16 h-16 mx-auto mb-4 bg-blue-600 rounded-xl flex items-center justify-center">
                    <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"></path>
                    </svg>
                </div>
                <h1 class="text-2xl font-bold text-gray-900">Kasir Skanilan</h1>
                <p class="text-gray-500 mt-1">Masuk sebagai kasir</p>
            </div>

            <!-- Login Form -->
            <form id="login-form" @submit.prevent="login" class="space-y-6">
                <div>
                    <label for="device_id" class="block text-sm font-medium text-gray-700 mb-2">Pilih Console</label>
                    <select id="device_id" name="device_id" required
                        class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors text-lg"
                        x-model="selectedDeviceId">
                        <option value="">-- Pilih Console --</option>
                        <template x-for="console in consoles" :key="console.device_id">
                            <option :value="console.device_id" x-text="console.name + ' (' + console.device_id + ')'"></option>
                        </template>
                    </select>
                </div>

                <div>
                    <label for="password" class="block text-sm font-medium text-gray-700 mb-2">Password</label>
                    <div class="relative">
                        <input type="password" id="password" name="password" required
                            class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors text-lg pr-12"
                            x-model="password"
                            :disabled="loading"
                            @input="showError = false">
                        <button type="button" @click="togglePassword" class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600">
                            <svg x-show="!showPassword" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                            </svg>
                            <svg x-show="showPassword" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"></path>
                            </svg>
                        </button>
                    </div>
                    <div x-show="showError" class="mt-2 text-sm text-red-600" x-text="errorMessage"></div>
                </div>

                <button type="submit" 
                    :disabled="loading"
                    class="w-full py-3 bg-blue-600 text-white font-semibold rounded-xl hover:bg-blue-700 focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition-colors disabled:opacity-50 disabled:cursor-not-allowed text-lg">
                    <span x-show="!loading">Masuk</span>
                    <span x-show="loading" class="flex items-center justify-center gap-2">
                        <svg class="animate-spin h-5 w-5" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                        Memproses...
                    </span>
                </button>
            </form>

            <div class="mt-6 text-center text-sm text-gray-500">
                <p>Lupa password? Hubungi admin untuk kode reset.</p>
            </div>
        </div>
    </div>

    <script>
        function loginForm() {
            return {
                consoles: @json($consoles),
                selectedDeviceId: '',
                password: '',
                showPassword: false,
                loading: false,
                showError: false,
                errorMessage: '',

                togglePassword() {
                    this.showPassword = !this.showPassword;
                    const input = document.getElementById('password');
                    input.type = this.showPassword ? 'text' : 'password';
                },

                async login() {
                    if (!this.selectedDeviceId || !this.password) {
                        this.showError = true;
                        this.errorMessage = 'Device ID dan password wajib diisi';
                        return;
                    }

                    this.loading = true;
                    this.showError = false;

                    try {
                        const response = await fetch('/api/cashier/login', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                            },
                            body: JSON.stringify({
                                device_id: this.selectedDeviceId,
                                password: this.password,
                            })
                        });

                        const data = await response.json();

                        if (data.success) {
                            // Store token in localStorage
                            localStorage.setItem('cashier_token', data.data.token);
                            localStorage.setItem('cashier_device_id', data.data.device_id);
                            localStorage.setItem('cashier_console_name', data.data.console_name);
                            localStorage.setItem('cashier_name', data.data.cashier_name);
                            
                            // Redirect to menu
                            window.location.href = '{{ route("cashier.menu") }}';
                        } else {
                            this.showError = true;
                            this.errorMessage = data.message || 'Login gagal';
                        }
                    } catch (e) {
                        this.showError = true;
                        this.errorMessage = 'Terjadi kesalahan koneksi';
                    } finally {
                        this.loading = false;
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
                    setTimeout(() => this.remove(id), 5000);
                },
                remove(id) {
                    this.toasts = this.toasts.filter(t => t.id !== id);
                }
            }
        }
    </script>
</x-layouts.cashier>