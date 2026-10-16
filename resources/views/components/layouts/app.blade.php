@props(['realtime' => null])
{{--
    Halaman admin selalu memakai Realtime (Ticket 09), jadi kalau view tidak
    mengirimkannya, layout menyediakannya sendiri.

    `clientConfig()` tanpa device id cukup untuk admin: yang dibutuhkan hanya
    channel dashboard. Channel kasir tidak pernah dibuka dari sisi admin.
--}}
@php($realtime ??= \App\Services\Realtime\RealtimeTopic::clientConfig())
<!DOCTYPE html>
<html lang="id" x-data="{ sidebarOpen: false }">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ config('app.name', 'Kasir Skanilan') }} - {{ $title ?? 'Dashboard' }}</title>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'sans-serif'],
                    },
                }
            }
        }
    </script>
{{-- Ticket 09: Realtime untuk dashboard admin (transaksi baru, status konsol).
         Taruh di head supaya `KasirRealtime` sudah ada sebelum script halaman. --}}
    @isset($realtime)
        @include('partials.realtime-init', ['realtime' => $realtime])
    @endisset
</head>
<body class="bg-gray-50 min-h-screen font-sans">
    <!-- Sidebar overlay for mobile -->
    <div x-show="sidebarOpen" x-transition:enter="transition-opacity ease-linear duration-300" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="transition-opacity ease-linear duration-300" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" class="fixed inset-0 z-40 bg-black bg-opacity-50 lg:hidden" @click="sidebarOpen = false" aria-hidden="true"></div>

    <!-- Sidebar -->
    <aside x-show="sidebarOpen" x-transition:enter="transition ease-in-out duration-300" x-transition:enter-start="-translate-x-full" x-transition:enter-end="translate-x-0" x-transition:leave="transition ease-in-out duration-300" x-transition:leave-start="translate-x-0" x-transition:leave-end="-translate-x-full" class="fixed inset-y-0 left-0 z-50 w-64 bg-white shadow-xl lg:relative lg:translate-x-0 lg:z-auto">
        <div class="flex flex-col h-full">
            <!-- Logo -->
            <div class="flex items-center justify-between h-16 px-6 border-b border-gray-200">
                <a href="{{ route('admin.dashboard') }}" class="text-xl font-bold text-gray-900">
                    {{ config('app.name', 'Kasir Skanilan') }}
                </a>
                <button @click="sidebarOpen = false" class="lg:hidden text-gray-500 hover:text-gray-700" aria-label="Tutup sidebar">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>

            <!-- Navigation -->
            <nav class="flex-1 px-4 py-6 space-y-1 overflow-y-auto">
                <div>
                    <p class="px-3 text-xs font-semibold text-gray-400 uppercase tracking-wider">Menu Utama</p>
                    <a href="{{ route('admin.dashboard') }}" class="flex items-center px-3 py-2.5 text-gray-700 rounded-lg hover:bg-gray-100 hover:text-gray-900 transition-colors {{ request()->routeIs('admin.dashboard') ? 'bg-gray-100 text-gray-900 font-medium' : '' }}">
                        <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"></path></svg>
                        Dashboard
                    </a>
                </div>

                <div class="mt-6">
                    <p class="px-3 text-xs font-semibold text-gray-400 uppercase tracking-wider">Manajemen</p>
                    <a href="{{ route('admin.consoles.index') }}" class="flex items-center px-3 py-2.5 text-gray-700 rounded-lg hover:bg-gray-100 hover:text-gray-900 transition-colors {{ request()->routeIs('admin.consoles*') ? 'bg-gray-100 text-gray-900 font-medium' : '' }}">
                        <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path></svg>
                        Console
                    </a>
                    <a href="{{ route('admin.menu.index') }}" class="flex items-center px-3 py-2.5 text-gray-700 rounded-lg hover:bg-gray-100 hover:text-gray-900 transition-colors {{ request()->routeIs('admin.menu*') ? 'bg-gray-100 text-gray-900 font-medium' : '' }}">
                        <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path></svg>
                        Menu
                    </a>
                    <a href="{{ route('admin.users.index') }}" class="flex items-center px-3 py-2.5 text-gray-700 rounded-lg hover:bg-gray-100 hover:text-gray-900 transition-colors {{ request()->routeIs('admin.users*') ? 'bg-gray-100 text-gray-900 font-medium' : '' }}">
                        <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path></svg>
                        Pengguna
                    </a>
                </div>

                <div class="mt-6">
                    <p class="px-3 text-xs font-semibold text-gray-400 uppercase tracking-wider">Laporan</p>
                    <a href="{{ route('admin.reports.index') }}" class="flex items-center px-3 py-2.5 text-gray-700 rounded-lg hover:bg-gray-100 hover:text-gray-900 transition-colors {{ request()->routeIs('admin.reports*') ? 'bg-gray-100 text-gray-900 font-medium' : '' }}">
                        <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                        Laporan
                    </a>
                    <a href="{{ route('admin.audit-logs.index') }}" class="flex items-center px-3 py-2.5 text-gray-700 rounded-lg hover:bg-gray-100 hover:text-gray-900 transition-colors {{ request()->routeIs('admin.audit-logs*') ? 'bg-gray-100 text-gray-900 font-medium' : '' }}">
                        <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                        Audit Log
                    </a>
                    <a href="{{ route('admin.settings.index') }}" class="flex items-center px-3 py-2.5 text-gray-700 rounded-lg hover:bg-gray-100 hover:text-gray-900 transition-colors {{ request()->routeIs('admin.settings*') ? 'bg-gray-100 text-gray-900 font-medium' : '' }}">
                        <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                        Pengaturan
                    </a>
                    <a href="{{ route('admin.password-resets.create') }}" class="flex items-center px-3 py-2.5 text-gray-700 rounded-lg hover:bg-gray-100 hover:text-gray-900 transition-colors {{ request()->routeIs('admin.password-resets*') ? 'bg-gray-100 text-gray-900 font-medium' : '' }}">
                        <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1115 7z"></path></svg>
                        Reset Password
                    </a>
                </div>

                <div class="mt-6">
                    <p class="px-3 text-xs font-semibold text-gray-400 uppercase tracking-wider">Pengaturan</p>
                    <a href="#" class="flex items-center px-3 py-2.5 text-gray-700 rounded-lg hover:bg-gray-100 hover:text-gray-900 transition-colors">
                        <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"></path></svg>
                        Password
                    </a>
                </div>
            </nav>

            <!-- User info bottom -->
            <div class="p-4 border-t border-gray-200">
                <div class="flex items-center">
                    <div class="w-8 h-8 rounded-full bg-blue-100 flex items-center justify-center">
                        <svg class="w-5 h-5 text-blue-600" fill="currentColor" viewBox="0 0 20 20"><path d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z"></path></svg>
                    </div>
                    <div class="ml-3 flex-1 min-w-0">
                        <p class="text-sm font-medium text-gray-900 truncate">{{ auth()->user()->name }}</p>
                        <p class="text-xs text-gray-500 truncate">Admin</p>
                    </div>
                </div>
            </div>
        </div>
    </aside>

    <!-- Main content -->
    <div class="lg:pl-64 min-h-screen">
        <!-- Top bar -->
        <header class="sticky top-0 z-30 bg-white border-b border-gray-200">
            <div class="flex items-center justify-between h-16 px-4 sm:px-6 lg:px-8">
                <button @click="sidebarOpen = true" class="lg:hidden p-2 text-gray-500 hover:text-gray-700" aria-label="Buka sidebar">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                    </svg>
                </button>
                <div class="flex-1 lg:flex-none"></div>
                <div class="flex items-center space-x-4">
                    <form method="POST" action="{{ route('admin.logout') }}" class="inline">
                        @csrf
                        <button type="submit" class="text-sm text-gray-600 hover:text-gray-900 font-medium px-3 py-2 rounded-lg hover:bg-gray-100 transition-colors">
                            Keluar
                        </button>
                    </form>
                </div>
            </div>
        </header>

        <!-- Flash messages: tampil di semua halaman admin -->
        <div class="px-4 sm:px-6 lg:px-8 pt-6 space-y-3">
            @if (session('success'))
                <div class="flex items-start p-4 bg-green-50 border border-green-200 rounded-lg" role="status">
                    <svg class="w-5 h-5 mr-3 text-green-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    <p class="text-sm text-green-800">{{ session('success') }}</p>
                </div>
            @endif

            @if (session('error'))
                <div class="flex items-start p-4 bg-red-50 border border-red-200 rounded-lg" role="alert">
                    <svg class="w-5 h-5 mr-3 text-red-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    <p class="text-sm text-red-800">{{ session('error') }}</p>
                </div>
            @endif

            @if ($errors->any())
                <div class="p-4 bg-red-50 border border-red-200 rounded-lg" role="alert">
                    <ul class="text-sm text-red-700 space-y-1 list-disc list-inside">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>

        <!-- Page content -->
        <main class="py-8 px-4 sm:px-6 lg:px-8">
            {{ $slot }}
        </main>
    </div>
</body>
</html>