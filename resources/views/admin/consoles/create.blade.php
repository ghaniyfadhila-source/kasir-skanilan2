<x-layouts.app :title="'Tambah Console'">
    <div class="max-w-2xl mx-auto space-y-6">
        <!-- Header -->
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Tambah Console Baru</h1>
            <p class="mt-1 text-gray-600">Daftarkan terminal kasir baru ke sistem</p>
        </div>

        <form method="POST" action="{{ route('admin.consoles.store') }}" class="space-y-6 bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            @csrf

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
                <label for="device_id" class="block text-sm font-medium text-gray-700 mb-1">Device ID <span class="text-red-500">*</span></label>
                <div class="flex items-center gap-2">
                    <input type="text"
                           id="device_id"
                           name="device_id"
                           value="{{ old('device_id', 'ESP-' . strtoupper(\Illuminate\Support\Str::random(12))) }}"
                           required
                           readonly
                           class="flex-1 px-4 py-2.5 border border-gray-300 rounded-lg bg-gray-50 font-mono text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors"
                           placeholder="ESP-XXXXXXXXXXXX">
                    <button type="button"
                            onclick="generateDeviceId()"
                            class="px-3 py-2.5 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors whitespace-nowrap text-sm">
                        Generate
                    </button>
                </div>
                <p class="mt-1 text-xs text-gray-500">Format: ESP-XXXXXXXXXXXX (12 karakter hex). Otomatis di-generate dari MAC address ESP8266.</p>
            </div>

            <div>
                <label for="name" class="block text-sm font-medium text-gray-700 mb-1">Nama Console <span class="text-red-500">*</span></label>
                <input type="text"
                       id="name"
                       name="name"
                       value="{{ old('name') }}"
                       required
                       maxlength="100"
                       class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors"
                       placeholder="Contoh: Kasir Kantin Utama">
            </div>

            <div>
                <label for="location" class="block text-sm font-medium text-gray-700 mb-1">Lokasi</label>
                <input type="text"
                       id="location"
                       name="location"
                       value="{{ old('location') }}"
                       maxlength="255"
                       class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors"
                       placeholder="Contoh: Lantai 1, Dekat Pintu Masuk">
            </div>

            <div>
                <label class="flex items-center">
                    <input type="checkbox"
                           name="is_active"
                           value="1"
                           {{ old('is_active', true) ? 'checked' : '' }}
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
                    Simpan Console
                </button>
            </div>
        </form>
    </div>

    <script>
        function generateDeviceId() {
            const chars = '0123456789ABCDEF';
            let deviceId = 'ESP-';
            for (let i = 0; i < 12; i++) {
                deviceId += chars.charAt(Math.floor(Math.random() * chars.length));
            }
            document.getElementById('device_id').value = deviceId;
        }
    </script>
</x-layouts.app>