<x-layouts.app :title="'Edit User'">
    <div class="max-w-3xl mx-auto space-y-6">
        <!-- Header -->
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">Edit User</h1>
                <p class="mt-1 text-gray-600">{{ $user->name }} · {{ $user->email }}</p>
            </div>
            <a href="{{ route('admin.users.show', $user) }}"
               class="text-sm font-medium text-gray-600 hover:text-gray-900">
                Lihat Detail &rarr;
            </a>
        </div>

        <!-- Credentials (shown once) -->
        @if (session('credentials'))
            <div x-data="{ open: true }" class="bg-amber-50 border border-amber-300 rounded-xl p-4" x-show="open">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h3 class="font-semibold text-amber-900">Kredensial Kasir (ditampilkan sekali)</h3>
                        <dl class="mt-2 space-y-1 text-sm text-amber-800 font-mono">
                            <div><span class="text-amber-700">Device ID:</span> {{ session('credentials.device_id') }}</div>
                            <div><span class="text-amber-700">Password:</span> {{ session('credentials.password') }}</div>
                        </dl>
                    </div>
                    <button type="button" @click="open = false" class="text-amber-700 hover:text-amber-900" title="Tutup">&times;</button>
                </div>
            </div>
        @endif

        <form method="POST" action="{{ route('admin.users.update', $user) }}" class="space-y-6 bg-white rounded-xl shadow-sm border border-gray-200 p-6" x-data="userEditForm('{{ $user->role }}')">
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

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                <div>
                    <label for="name" class="block text-sm font-medium text-gray-700 mb-1">Nama Lengkap <span class="text-red-500">*</span></label>
                    <input type="text" id="name" name="name" value="{{ old('name', $user->name) }}" required maxlength="100"
                           class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors">
                </div>
                <div>
                    <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Email <span class="text-red-500">*</span></label>
                    <input type="email" id="email" name="email" value="{{ old('email', $user->email) }}" required maxlength="255"
                           class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors">
                </div>
            </div>

            <div>
                <label for="role" class="block text-sm font-medium text-gray-700 mb-1">Role <span class="text-red-500">*</span></label>
                <select id="role" name="role" required x-model="role"
                        class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors">
                    <option value="student" {{ $user->role === 'student' ? 'selected' : '' }}>Siswa</option>
                    <option value="cashier" {{ $user->role === 'cashier' ? 'selected' : '' }}>Kasir</option>
                </select>
            </div>

            <div x-show="role === 'cashier'" x-transition>
                <label for="device_id" class="block text-sm font-medium text-gray-700 mb-1">Device ID Console <span class="text-red-500">*</span></label>
                <select id="device_id" name="device_id"
                        class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors">
                    <option value="">— Tidak ada —</option>
                    @foreach ($unassignedConsoles as $console)
                        <option value="{{ $console->device_id }}" {{ $user->device_id === $console->device_id ? 'selected' : '' }}>
                            {{ $console->device_id }} — {{ $console->name }}{{ $console->location ? ' (' . $console->location . ')' : '' }}
                        </option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-gray-500">Role Kasir memerlukan console yang belum dipakai kasir lain.</p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                <div>
                    <label for="password" class="block text-sm font-medium text-gray-700 mb-1">Password Baru <span class="text-gray-400">(opsional)</span></label>
                    <input type="password" id="password" name="password" minlength="8" autocomplete="new-password"
                           class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors"
                           placeholder="Kosongkan jika tidak diubah">
                </div>
                <div>
                    <label for="password_confirmation" class="block text-sm font-medium text-gray-700 mb-1">Konfirmasi Password</label>
                    <input type="password" id="password_confirmation" name="password_confirmation" minlength="8" autocomplete="new-password"
                           class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors">
                </div>
            </div>

            <div class="flex justify-end gap-3 pt-4 border-t border-gray-200">
                <a href="{{ route('admin.users.index') }}"
                   class="px-4 py-2.5 text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors">Kembali</a>
                <button type="submit" class="px-4 py-2.5 bg-blue-600 text-white font-medium rounded-lg hover:bg-blue-700 transition-colors">
                    Simpan Perubahan
                </button>
            </div>
        </form>

        <!-- Saldo -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6"
             x-data="balanceCard({{ $user->balance_cents }})">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                <h2 class="text-lg font-semibold text-gray-900">Saldo</h2>
                <p class="text-2xl font-bold text-gray-900 tabular-nums">
                    Rp <span x-text="format(balance)">{{ number_format($user->balance_cents / 100, 0, ',', '.') }}</span>
                </p>
            </div>

            @if ($user->role === 'student')
                <form method="POST" action="{{ route('admin.users.topup', $user) }}" class="mt-4 grid grid-cols-1 sm:grid-cols-3 gap-4" x-data="topUpForm()">
                    @csrf
                    <div class="sm:col-span-1">
                        <label for="amount" class="block text-sm font-medium text-gray-700 mb-1">Nominal (Rp) <span class="text-red-500">*</span></label>
                        <input type="number" id="amount" name="amount" x-model="amount" min="1000" max="50000000" step="1000" required
                               class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors"
                               placeholder="5000">
                    </div>
                    <div class="sm:col-span-1">
                        <label for="description" class="block text-sm font-medium text-gray-700 mb-1">Keterangan</label>
                        <input type="text" id="description" name="description" x-model="description" maxlength="255"
                               class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors"
                               placeholder="Top-up Tunai">
                    </div>
                    <div class="sm:col-span-1 flex items-end">
                        <button type="submit" :disabled="!isValid"
                                class="w-full px-4 py-2.5 bg-emerald-600 text-white font-medium rounded-lg hover:bg-emerald-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors">
                            Top-up Saldo
                        </button>
                    </div>
                </form>
            @else
                <p class="mt-3 text-sm text-gray-500">Top-up saldo hanya tersedia untuk user dengan role Siswa.</p>
            @endif
        </div>

        <!-- Kartu RFID -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6" x-data="{ addOpen: false }">
            <div class="flex items-center justify-between gap-4">
                <h2 class="text-lg font-semibold text-gray-900">Kartu RFID</h2>
                <button type="button" @click="addOpen = !addOpen"
                        class="inline-flex items-center px-3 py-2 bg-blue-600 text-white text-sm font-medium rounded-lg hover:bg-blue-700 transition-colors">
                    <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                    </svg>
                    Tambah Kartu
                </button>
            </div>

            <!-- Form tambah kartu -->
            <form method="POST" action="{{ route('admin.users.rfid.store', $user) }}"
                  x-show="addOpen" x-collapse x-cloak
                  class="mt-4 p-4 bg-gray-50 border border-gray-200 rounded-lg space-y-4">
                @csrf
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label for="rfid_uid" class="block text-sm font-medium text-gray-700 mb-1">UID Kartu <span class="text-red-500">*</span></label>
                        <input type="text" id="rfid_uid" name="rfid_uid" value="{{ old('rfid_uid') }}" required
                               maxlength="8" pattern="[0-9A-Fa-f]{8}"
                               x-data="{ uid: '{{ old('rfid_uid') }}' }"
                               x-model="uid"
                               @input="uid = uid.toUpperCase()"
                               class="w-full px-4 py-2.5 border border-gray-300 rounded-lg font-mono text-sm uppercase focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors"
                               placeholder="A1B2C3D4">
                        <p class="mt-1 text-xs text-gray-500">Format MIFARE Classic 4-byte, contoh <code class="font-mono">A1B2C3D4</code> (8 karakter hex, huruf besar).</p>
                    </div>
                    <div>
                        <label for="label" class="block text-sm font-medium text-gray-700 mb-1">Label</label>
                        <input type="text" id="label" name="label" value="{{ old('label') }}" maxlength="100"
                               class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors"
                               placeholder="Kartu utama / Cadangan">
                    </div>
                </div>
                <div class="flex justify-end gap-3">
                    <button type="button" @click="addOpen = false"
                            class="px-4 py-2.5 text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors">Batal</button>
                    <button type="submit" class="px-4 py-2.5 bg-blue-600 text-white font-medium rounded-lg hover:bg-blue-700 transition-colors">
                        Simpan Kartu
                    </button>
                </div>
            </form>

            <!-- Tabel kartu -->
            <div class="mt-4 overflow-x-auto">
                @if ($user->rfidCards->isEmpty())
                    <p class="text-sm text-gray-500 py-4 text-center">Belum ada kartu RFID assigned.</p>
                @else
                    <table class="w-full">
                        <thead class="bg-gray-50 border-b border-gray-200">
                            <tr>
                                <th class="px-4 py-2.5 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">UID</th>
                                <th class="px-4 py-2.5 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Label</th>
                                <th class="px-4 py-2.5 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Diterbitkan</th>
                                <th class="px-4 py-2.5 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                <th class="px-4 py-2.5 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Aksi</th>
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
                                    <td class="px-4 py-3 text-right">
                                        @if (! $card->revoked_at)
                                            <form method="POST" action="{{ route('admin.users.rfid.destroy', [$user, $card]) }}" class="inline">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="text-red-600 hover:text-red-900 text-sm font-medium"
                                                        onclick="return confirm('Cabut kartu RFID {{ $card->rfid_uid }}?')">Cabut</button>
                                            </form>
                                        @else
                                            <span class="text-xs text-gray-400">-</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>
    </div>

    <script>
        function userEditForm(role) {
            return { role: role }
        }

        function balanceCard(cents) {
            return {
                balance: cents,
                format(value) {
                    return new Intl.NumberFormat('id-ID').format(value / 100)
                },
            }
        }

        function topUpForm() {
            return {
                amount: '',
                description: '',
                get isValid() {
                    const n = Number(this.amount)
                    return Number.isFinite(n) && n >= 1000 && n <= 50000000
                },
            }
        }
    </script>
</x-layouts.app>