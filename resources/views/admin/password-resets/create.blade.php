<x-layouts.app :title="'Reset Password'">
    <div class="max-w-2xl mx-auto space-y-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Reset Password</h1>
            <p class="mt-1 text-gray-600">
                Buat kode reset 6 digit, lalu berikan ke pemilik akun.
                Kode hanya ditampilkan satu kali.
            </p>
        </div>

        <!-- Kode yang baru dibuat (sekali tampil) -->
        @if (session('reset_code'))
            @php $info = session('reset_code'); @endphp
            <div x-data="{ copied: false }" class="p-6 bg-amber-50 border-2 border-amber-300 rounded-xl">
                <div class="flex items-start justify-between gap-4">
                    <div class="flex-1">
                        <p class="text-sm font-medium text-amber-800">
                            Kode reset untuk <strong>{{ $info['user_name'] }}</strong>
                        </p>
                        <p class="mt-3 font-mono text-4xl font-bold tracking-[0.3em] text-amber-900">
                            {{ $info['code'] }}
                        </p>
                        <p class="mt-2 text-xs text-amber-700">
                            Berlaku sampai {{ \Illuminate\Support\Carbon::parse($info['expires_at'])->format('d M Y H:i') }}.
                            Kode hanya bisa dipakai satu kali.
                        </p>
                    </div>
                    <button type="button"
                            @click="navigator.clipboard.writeText('{{ $info['code'] }}'); copied = true; setTimeout(() => copied = false, 2000)"
                            class="px-3 py-2 bg-amber-600 text-white text-sm font-medium rounded-lg hover:bg-amber-700 transition-colors whitespace-nowrap">
                        <span x-text="copied ? 'Tersalin!' : 'Salin'"></span>
                    </button>
                </div>
                <p class="mt-4 text-xs text-amber-700">
                    <strong>Penting:</strong> setelah halaman ini ditutup, kode tidak bisa dilihat lagi.
                </p>
            </div>
        @endif

        <!-- Pilih user -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <h2 class="text-lg font-semibold text-gray-900">Buat Kode Reset</h2>

            <form method="POST" action="{{ route('admin.password-resets.store') }}" class="mt-4 space-y-4">
                @csrf

                <div>
                    <label for="user_id" class="block text-sm font-medium text-gray-700 mb-1">
                        Pilih User <span class="text-red-500">*</span>
                    </label>
                    <select id="user_id" name="user_id" required
                            class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors">
                        <option value="">-- Pilih user --</option>
                        @foreach ($users as $u)
                            <option value="{{ $u->id }}" {{ (string) $u->id === (string) old('user_id', $user?->id) ? 'selected' : '' }}>
                                {{ $u->name }} — {{ $u->email }} ({{ $u->role === 'student' ? 'Siswa' : 'Kasir' }})
                            </option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-gray-500">
                        Role Admin tidak bisa di-reset lewat halaman ini; admin mengelola passwordnya sendiri di menu Pengaturan.
                    </p>
                </div>

                <div class="flex justify-end gap-3 pt-2 border-t border-gray-100">
                    <button type="submit" class="px-4 py-2.5 bg-blue-600 text-white font-medium rounded-lg hover:bg-blue-700 transition-colors">
                        Buat Kode
                    </button>
                </div>
            </form>
        </div>

        <!-- Batalkan kode aktif -->
        @if ($user)
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <h2 class="text-lg font-semibold text-gray-900">Kode Aktif</h2>
                @php $active = \App\Models\PasswordReset::where('user_id', $user->id)->valid()->count(); @endphp

                @if ($active > 0)
                    <p class="mt-2 text-sm text-gray-600">
                        {{ $user->name }} punya {{ $active }} kode yang masih berlaku.
                    </p>
                    <form method="POST" action="{{ route('admin.password-resets.destroy', $user) }}" class="mt-4">
                        @csrf
                        @method('DELETE')
                        <button type="submit"
                                onclick="return confirm('Cabut semua kode reset aktif untuk {{ $user->name }}?')"
                                class="px-4 py-2.5 bg-red-600 text-white font-medium rounded-lg hover:bg-red-700 transition-colors">
                            Cabut Semua Kode Aktif
                        </button>
                    </form>
                @else
                    <p class="mt-2 text-sm text-gray-500">Tidak ada kode aktif untuk {{ $user->name }}.</p>
                @endif
            </div>
        @endif
    </div>
</x-layouts.app>