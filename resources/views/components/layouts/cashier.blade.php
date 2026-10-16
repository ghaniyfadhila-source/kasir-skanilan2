@props(['realtime' => null])
<!DOCTYPE html>
<html lang="id" x-data="{ sidebarOpen: false }">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>{{ $title ?? 'Kasir Skanilan' }}</title>
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
    @stack('styles')
    {{-- Ticket 09: Realtime hanya dimuat kalau ada konsol yang sedang login,
         supaya halaman login kasir tidak mengunduh pustaka yang tidak dipakai. --}}
    @if($realtime ?? false)
        @include('partials.realtime-init', ['realtime' => $realtime])
    @endif
</head>
<body class="bg-gray-50 min-h-screen font-sans">
    <!-- Toast Container -->
    <div id="toast-container" class="fixed top-4 right-4 z-50 flex flex-col gap-2" x-data="toastContainer()"></div>

    @yield('content')

    @stack('scripts')
</body>
</html>