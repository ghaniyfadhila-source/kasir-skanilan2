<x-layouts.app :title="'Edit Console: ' . $console->name">
    <div class="max-w-3xl mx-auto space-y-6">
        <!-- Header -->
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">Edit Console</h1>
                <p class="mt-1 text-gray-600">{{ $console->device_id }}</p>
            </div>
            <a href="{{ route('admin.consoles.index') }}"
               class="px-4 py-2 text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors">
                Kembali
            </a>
        </div>

        @if (session('credentials') || session('rotated_credentials'))
            @php
                $creds = session('credentials') ?? session('rotated_credentials');
                $isRotated = session()->has('rotated_credentials');
            @endphp
            <div class="p-4 bg-blue-50 border border-blue-200 rounded-lg" x-data="{ showCredentials: true }">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="font-medium text-blue-900">
                        {{ $isRotated ? 'Kredensial Baru (Dirotasi)' : 'Kredensial Baru (Dibuat)' }}
                    </h3>
                    <button @click="showCredentials = !showCredentials"
                            class="text-blue-700 hover:text-blue-900 text-sm font-medium">
                        <span x-show="showCredentials">Sembunyikan</span>
                        <span x-show="!showCredentials">Tampilkan</span>
                    </button>
                </div>
                <div x-show="showCredentials" x-transition>
                    <div class="space-y-3 text-sm">
                        <div>
                            <label class="block text-blue-800 font-medium mb-1">Device ID</label>
                            <code class="block bg-blue-100 px-3 py-2 rounded font-mono text-blue-900">{{ $creds['device_id'] }}</code>
                        </div>
                        <div>
                            <label class="block text-blue-800 font-medium mb-1">Password</label>
                            <div class="flex gap-2">
                                <code class="flex-1 bg-blue-100 px-3 py-2 rounded font-mono text-blue-900" x-ref="password">{{ $creds['password'] }}</code>
                                <button @click="copyToClipboard($refs.password, 'Password disalin!')"
                                        class="px-3 py-2 bg-blue-100 text-blue-800 rounded hover:bg-blue-200 transition-colors whitespace-nowrap">
                                    Salin
                                </button>
                            </div>
                        </div>
                        <div>
                            <label class="block text-blue-800 font-medium mb-1">API Key</label>
                            <div class="flex gap-2">
                                <code class="flex-1 bg-blue-100 px-3 py-2 rounded font-mono text-blue-900" x-ref="apiKey">{{ $creds['api_key'] }}</code>
                                <button @click="copyToClipboard($refs.apiKey, 'API Key disalin!')"
                                        class="px-3 py-2 bg-blue-100 text-blue-800 rounded hover:bg-blue-200 transition-colors whitespace-nowrap">
                                    Salin
                                </button>
                            </div>
                        </div>
                    </div>
                    <p class="mt-3 text-xs text-blue-700">
                        <strong>Penting:</strong> Kredensial ini hanya ditampilkan sekali. Simpan di tempat yang aman.
                        Password digunakan untuk login console, API Key untuk autentikasi ke backend.
                    </p>
                </div>
            </div>
        @endif

        <!-- Form Edit -->
        <form method="POST" action="{{ route('admin.consoles.update', $console) }}" class="space-y-6 bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            @csrf
            @method('PUT')

            @if ($errors->any())
                <div class="p-4 bg-red-50 border border-red-200 rounded-lg">
                    <ul class="text-sm text-red-700 space-y-1">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Device ID</label>
                <code class="block px-4 py-2.5 bg-gray-50 border border-gray-300 rounded-lg font-mono text-sm text-gray-900">{{ $console->device_id }}</code>
                <p class="mt-1 text-xs text-gray-500">Device ID tidak dapat diubah setelah dibuat.</p>
            </div>

            <div>
                <label for="name" class="block text-sm font-medium text-gray-700 mb-1">Nama Console <span class="text-red-500">*</span></label>
                <input type="text"
                       id="name"
                       name="name"
                       value="{{ old('name', $console->name) }}"
                       required
                       maxlength="100"
                       class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors">
            </div>

            <div>
                <label for="location" class="block text-sm font-medium text-gray-700 mb-1">Lokasi</label>
                <input type="text"
                       id="location"
                       name="location"
                       value="{{ old('location', $console->location) }}"
                       maxlength="255"
                       class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors">
            </div>

            <div>
                <label for="status" class="block text-sm font-medium text-gray-700 mb-1">Status <span class="text-red-500">*</span></label>
                <select id="status"
                        name="status"
                        class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors">
                    <option value="online" {{ old('status', $console->status) === 'online' ? 'selected' : '' }}>Online</option>
                    <option value="offline" {{ old('status', $console->status) === 'offline' ? 'selected' : '' }}>Offline</option>
                    <option value="maintenance" {{ old('status', $console->status) === 'maintenance' ? 'selected' : '' }}>Maintenance</option>
                </select>
            </div>

            <div>
                <label class="flex items-center">
                    <input type="checkbox"
                           name="is_active"
                           value="1"
                           {{ old('is_active', $console->is_active) ? 'checked' : '' }}
                           class="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500">
                    <span class="ml-2 text-sm text-gray-700">Aktif</span>
                </label>
            </div>

            <div class="flex justify-end gap-3 pt-4 border-t border-gray-200">
                <a href="{{ route('admin.consoles.index') }}"
                   class="px-4 py-2.5 text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors">
                    Batal
                </a>
                <button type="submit"
                        class="px-4 py-2.5 bg-blue-600 text-white font-medium rounded-lg hover:bg-blue-700 transition-colors">
                    Simpan Perubahan
                </button>
            </div>
        </form>

        <!-- Antrean Offline (Ticket 10) -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <div class="flex flex-wrap items-center justify-between gap-2 mb-4">
                <h3 class="text-lg font-medium text-gray-900">Antrean Offline</h3>
                <span class="text-xs text-gray-500">
                    {{ $console->queue_reported_at ? 'Dilaporkan ' . $console->queue_reported_at->diffForHumans() : 'Belum pernah dilaporkan' }}
                </span>
            </div>

            <div class="grid grid-cols-2 gap-4 mb-4">
                <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
                    <p class="text-sm text-yellow-800">Menunggu dikirim</p>
                    <p class="mt-1 text-2xl font-bold text-yellow-900">{{ $console->queue_pending }}</p>
                </div>
                <div class="bg-red-50 border border-red-200 rounded-lg p-4">
                    <p class="text-sm text-red-800">Gagal (perlu tindakan)</p>
                    <p class="mt-1 text-2xl font-bold text-red-900">{{ $console->queue_failed }}</p>
                </div>
            </div>

            <p class="text-sm text-gray-600 mb-4">
                Tap RFID yang terjadi saat perangkat offline disimpan di LittleFS perangkat.
                Perintah di bawah dieksekusi pada heartbeat berikutnya (maksimal 30 detik),
                bukan seketika, karena perangkat tidak memakai koneksi WebSocket.
            </p>

            <form method="POST" action="{{ route('admin.consoles.queue.clear', $console) }}" class="inline"
                  onsubmit="return confirm('Kosongkan antrean tap offline perangkat ini? Tap yang belum terkirim akan hilang dan tidak ditagihkan.')">
                @csrf
                <button type="submit"
                        class="px-4 py-2.5 bg-gray-800 text-white font-medium rounded-lg hover:bg-gray-900 transition-colors">
                    Kosongkan Antrean
                </button>
            </form>

            @if ($commands->isNotEmpty())
                <div class="mt-5 pt-4 border-t border-gray-200">
                    <h4 class="text-sm font-medium text-gray-700 mb-2">Perintah Terakhir</h4>
                    <ul class="space-y-2">
                        @foreach ($commands as $command)
                            <li class="flex flex-wrap items-center justify-between gap-2 text-sm">
                                <span class="font-mono text-gray-900">{{ $command->command }}</span>
                                @if ($command->acknowledged_at)
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                                        {{ $command->result === 'ok' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">
                                        {{ $command->result === 'ok' ? 'Selesai' : 'Gagal' }} {{ $command->acknowledged_at->diffForHumans() }}
                                    </span>
                                @elseif ($command->created_at->lt(now()->subMinutes(\App\Models\ConsoleCommand::TTL_MINUTES)))
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-700">
                                        Kedaluwarsa
                                    </span>
                                @else
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">
                                        Menunggu perangkat
                                    </span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>

        <!-- Rotasi Kredensial (form terpisah: <form> bersarang tidak sah di HTML) -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <h3 class="text-lg font-medium text-gray-900 mb-4">Rotasi Kredensial</h3>
            <p class="text-sm text-gray-600 mb-4">
                Rotasi kredensial akan menghasilkan password dan API Key baru. Kredensial lama akan segera tidak valid.
                Gunakan jika kredensial terekspos atau untuk keamanan berkala.
            </p>
            <form method="POST" action="{{ route('admin.consoles.rotate-credentials', $console) }}" class="inline">
                @csrf
                <button type="submit"
                        onclick="return confirm('Yakin ingin merotasi kredensial? Kredensial lama akan segera tidak valid dan console perlu dikonfigurasi ulang.')"
                        class="px-4 py-2.5 bg-orange-600 text-white font-medium rounded-lg hover:bg-orange-700 transition-colors">
                    <svg class="w-5 h-5 mr-2 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                    </svg>
                    Rotasi Kredensial
                </button>
            </form>
        </div>

        <!-- Danger Zone -->
        <div class="bg-red-50 border border-red-200 rounded-xl p-6">
            <h3 class="text-lg font-medium text-red-900 mb-3">Zona Bahaya</h3>
            <p class="text-sm text-red-700 mb-4">
                Menghapus console akan menghapus semua data terkait termasuk kredensial. Tindakan ini tidak dapat dibatalkan.
                Console yang memiliki riwayat transaksi tidak dapat dihapus.
            </p>
            <form method="POST" action="{{ route('admin.consoles.destroy', $console) }}" class="inline"
                  onsubmit="return confirm('PERINGATAN: Ini akan menghapus console secara permanen. Yakin ingin melanjutkan?');">
                @csrf
                @method('DELETE')
                <button type="submit"
                        class="px-4 py-2.5 bg-red-600 text-white font-medium rounded-lg hover:bg-red-700 transition-colors">
                    <svg class="w-5 h-5 mr-2 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                    </svg>
                    Hapus Console
                </button>
            </form>
        </div>
    </div>

    <script>
        function copyToClipboard(element, successMessage) {
            navigator.clipboard.writeText(element.textContent.trim()).then(() => {
                const originalText = element.nextElementSibling?.textContent || 'Salin';
                element.nextElementSibling.textContent = successMessage;
                setTimeout(() => {
                    element.nextElementSibling.textContent = 'Salin';
                }, 2000);
            });
        }
    </script>
</x-layouts.app>