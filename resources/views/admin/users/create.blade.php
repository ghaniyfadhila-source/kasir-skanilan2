<x-layouts.app :title="'Tambah User'">
    <div class="max-w-2xl mx-auto space-y-6">
        <!-- Header -->
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Tambah User Baru</h1>
            <p class="mt-1 text-gray-600">Daftarkan pengguna baru (siswa atau kasir) ke sistem</p>
        </div>

        <form method="POST" action="{{ route('admin.users.store') }}" class="space-y-6 bg-white rounded-xl shadow-sm border border-gray-200 p-6" x-data="userCreateForm()">
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
                <label for="name" class="block text-sm font-medium text-gray-700 mb-1">Nama Lengkap <span class="text-red-500">*</span></label>
                <input type="text"
                       id="name"
                       name="name"
                       value="{{ old('name') }}"
                       required
                       maxlength="100"
                       class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors"
                       placeholder="Contoh: Budi Santoso">
            </div>

            <div>
                <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Email <span class="text-red-500">*</span></label>
                <input type="email"
                       id="email"
                       name="email"
                       value="{{ old('email') }}"
                       required
                       maxlength="255"
                       class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors"
                       placeholder="Contoh: budi@sekolah.sch.id">
            </div>

            <div>
                <label for="role" class="block text-sm font-medium text-gray-700 mb-1">Role <span class="text-red-500">*</span></label>
                <select id="role"
                        name="role"
                        required
                        x-model="selectedRole"
                        @change="handleRoleChange()"
                        class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors">
                    <option value="">Pilih Role</option>
                    <option value="student" {{ old('role') === 'student' ? 'selected' : '' }}>Siswa</option>
                    <option value="cashier" {{ old('role') === 'cashier' ? 'selected' : '' }}>Kasir</option>
                </select>
            </div>

            <!-- Cashier-specific fields -->
            <template x-if="selectedRole === 'cashier'">
                <div x-transition class="space-y-4">
                    <div class="p-4 bg-blue-50 border border-blue-200 rounded-lg">
                        <p class="text-sm text-blue-800">
                            <strong>Info:</strong> Role Kasir memerlukan Device ID console yang belum ter-assign.
                            Password akan di-generate otomatis dan ditampilkan sekali saja setelah disimpan.
                        </p>
                    </div>

                    <div>
                        <label for="device_id" class="block text-sm font-medium text-gray-700 mb-1">Device ID Console <span class="text-red-500">*</span></label>
                        <select id="device_id"
                                name="device_id"
                                required
                                class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors">
                            <option value="">Pilih Console</option>
                            @foreach ($unassignedConsoles as $console)
                                <option value="{{ $console->device_id }}" {{ old('device_id') === $console->device_id ? 'selected' : '' }}>
                                    {{ $console->device_id }} — {{ $console->name }}{{ $console->location ? ' (' . $console->location . ')' : '' }}
                                </option>
                            @endforeach
                        </select>
                        @if ($unassignedConsoles->isEmpty())
                            <p class="mt-1 text-sm text-red-600">Tidak ada console yang tersedia. Silakan buat console terlebih dahulu.</p>
                        @endif
                    </div>

                    <div>
                        <label for="password" class="block text-sm font-medium text-gray-700 mb-1">Password <span class="text-red-500">*</span></label>
                        <input type="password"
                               id="password"
                               name="password"
                               required
                               minlength="8"
                               class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors"
                               placeholder="Minimal 8 karakter">
                        <p class="mt-1 text-xs text-gray-500">Password untuk login ke aplikasi kasir</p>
                    </div>

                    <div>
                        <label for="password_confirmation" class="block text-sm font-medium text-gray-700 mb-1">Konfirmasi Password <span class="text-red-500">*</span></label>
                        <input type="password"
                               id="password_confirmation"
                               name="password_confirmation"
                               required
                               class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors"
                               placeholder="Ulangi password">
                    </div>
                </div>
            </template>

            <!-- Student-specific fields -->
            <template x-if="selectedRole === 'student'">
                <div x-transition class="space-y-4">
                    <div class="p-4 bg-green-50 border border-green-200 rounded-lg">
                        <p class="text-sm text-green-800">
                            <strong>Info:</strong> Role Siswa tidak memerlukan Device ID. Password opsional (akan di-generate otomatis jika kosong).
                        </p>
                    </div>

                    <div>
                        <label for="password" class="block text-sm font-medium text-gray-700 mb-1">Password <span class="text-gray-400">(opsional)</span></label>
                        <input type="password"
                               id="password"
                               name="password"
                               minlength="8"
                               class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors"
                               placeholder="Kosongkan untuk auto-generate">
                        <p class="mt-1 text-xs text-gray-500">Kosongkan untuk generate password acak 12 karakter</p>
                    </div>

                    <div>
                        <label for="password_confirmation" class="block text-sm font-medium text-gray-700 mb-1">Konfirmasi Password</label>
                        <input type="password"
                               id="password_confirmation"
                               name="password_confirmation"
                               class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors"
                               placeholder="Ulangi password jika diisi">
                    </div>
                </div>
            </template>

            <div>
                <label for="initial_balance" class="block text-sm font-medium text-gray-700 mb-1">Saldo Awal (Rupiah)</label>
                <input type="number"
                       id="initial_balance"
                       name="initial_balance"
                       value="{{ old('initial_balance', 0) }}"
                       min="0"
                       max="50000000"
                       step="1000"
                       class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors"
                       placeholder="0">
                <p class="mt-1 text-xs text-gray-500">Maksimal Rp 500.000. Akan dikonversi ke sen (dikali 100) untuk penyimpanan.</p>
            </div>

            <div class="flex justify-end gap-3 pt-4 border-t border-gray-200">
                <a href="{{ route('admin.users.index') }}"
                   class="px-4 py-2.5 text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors">
                    Batal
                </a>
                <button type="submit"
                        class="px-4 py-2.5 bg-blue-600 text-white font-medium rounded-lg hover:bg-blue-700 transition-colors">
                    Simpan User
                </button>
            </div>
        </form>
    </div>

    <script>
        function userCreateForm() {
            return {
                selectedRole: '{{ old("role", "") }}',
                handleRoleChange() {
                    // Role change handled by x-if
                }
            }
        }
    </script>
</x-layouts.app>