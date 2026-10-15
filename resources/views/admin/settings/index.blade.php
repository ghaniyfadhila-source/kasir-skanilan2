<x-layouts.app :title="'Pengaturan'">
    <div class="max-w-3xl mx-auto space-y-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Pengaturan</h1>
            <p class="mt-1 text-gray-600">Konfigurasi sistem dan akun admin.</p>
        </div>

        <!-- Profil -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 space-y-6">
            <div>
                <h2 class="text-lg font-semibold text-gray-900">Profil Admin</h2>
                <p class="text-sm text-gray-500">{{ auth()->user()->name }} &middot; {{ auth()->user()->email }}</p>
            </div>

            <form method="POST" action="{{ route('admin.settings.password') }}" class="space-y-4 pt-4 border-t border-gray-100">
                @csrf
                @method('PUT')

                @if ($errors->any() && $errors->hasAny(['current_password', 'password']))
                    <div class="p-4 bg-red-50 border border-red-200 rounded-lg">
                        <ul class="text-sm text-red-700 space-y-1">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <h3 class="text-sm font-semibold text-gray-700">Ubah Password</h3>

                <div>
                    <label for="current_password" class="block text-sm font-medium text-gray-700 mb-1">Password Saat Ini <span class="text-red-500">*</span></label>
                    <input type="password" id="current_password" name="current_password" required autocomplete="current-password"
                           class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 transition-colors">
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label for="new_password" class="block text-sm font-medium text-gray-700 mb-1">Password Baru <span class="text-red-500">*</span></label>
                        <input type="password" id="new_password" name="password" required minlength="8" autocomplete="new-password"
                               class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 transition-colors">
                    </div>
                    <div>
                        <label for="new_password_confirmation" class="block text-sm font-medium text-gray-700 mb-1">Konfirmasi <span class="text-red-500">*</span></label>
                        <input type="password" id="new_password_confirmation" name="password_confirmation" required minlength="8" autocomplete="new-password"
                               class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 transition-colors">
                    </div>
                </div>

                <div class="flex justify-end">
                    <button type="submit" class="px-4 py-2.5 bg-blue-600 text-white font-medium rounded-lg hover:bg-blue-700 transition-colors">
                        Ubah Password
                    </button>
                </div>
            </form>
        </div>

        <!-- Pengaturan sistem -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <h2 class="text-lg font-semibold text-gray-900">Pengaturan Sistem</h2>
            <p class="text-sm text-gray-500">Nilai ini langsung dipakai aplikasi tanpa perlu restart.</p>

            <form method="POST" action="{{ route('admin.settings.update') }}" class="mt-6 space-y-5">
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

                @foreach ($schema as $key => $def)
                    @if ($def['type'] === 'bool')
                        <div class="flex items-center">
                            <input type="hidden" name="{{ $key }}" value="0">
                            <label class="flex items-center gap-3">
                                <input type="checkbox" name="{{ $key }}" value="1"
                                       @checked((bool) $values[$key])
                                       class="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500">
                                <span class="text-sm text-gray-700">{{ $def['label'] }}</span>
                            </label>
                        </div>
                    @else
                        <div>
                            <label for="{{ $key }}" class="block text-sm font-medium text-gray-700 mb-1">
                                {{ $def['label'] }}
                                <span class="text-gray-400 font-normal">({{ $key }})</span>
                            </label>
                            <input type="{{ $def['type'] === 'int' ? 'number' : 'text' }}"
                                   id="{{ $key }}" name="{{ $key }}"
                                   value="{{ $values[$key] }}"
                                   @if ($def['type'] === 'int') min="1" @endif
                                   class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 transition-colors">
                        </div>
                    @endif
                @endforeach

                <div class="flex justify-end pt-4 border-t border-gray-200">
                    <button type="submit" class="px-4 py-2.5 bg-blue-600 text-white font-medium rounded-lg hover:bg-blue-700 transition-colors">
                        Simpan Pengaturan
                    </button>
                </div>
            </form>
        </div>

        <!-- Backup -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <h2 class="text-lg font-semibold text-gray-900">Backup</h2>
            <p class="mt-1 text-sm text-gray-500">
                Backup diambil dari repositori GitHub, bukan dari tombol di aplikasi,
                supaya salinan selalu ikut ter-versioning.
            </p>
            <div class="mt-4 p-4 bg-gray-50 border border-gray-200 rounded-lg">
                <p class="text-sm text-gray-700">
                    Database ada di <code class="font-mono text-xs">database/</code>, skema Supabase di
                    <code class="font-mono text-xs">database/supabase/schema.sql</code>.
                    Gunakan <code class="font-mono text-xs">git push</code> setelah
                    <code class="font-mono text-xs">php artisan migrate</code>.
                </p>
            </div>
        </div>
    </div>
</x-layouts.app>