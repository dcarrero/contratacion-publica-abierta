<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">

    <title>{{ $title ?? 'Admin' }} — Contratación Abierta</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen flex flex-col bg-gray-100 text-gray-800 antialiased">

    {{-- Header admin --}}
    <header class="bg-gray-900 text-white shadow-md">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-14">
                <div class="flex items-center gap-3">
                    <a href="{{ route('admin.dashboard') }}" class="font-bold text-lg tracking-tight hover:opacity-90">
                        Admin
                    </a>
                    <span class="text-gray-400 text-sm">Contratación Abierta</span>
                </div>

                <div class="flex items-center gap-4 text-sm">
                    <a href="{{ route('dashboard') }}" class="text-gray-300 hover:text-white transition" target="_blank">
                        Ver sitio &rarr;
                    </a>
                    @auth
                        <form method="POST" action="{{ route('admin.logout') }}" class="inline">
                            @csrf
                            <button type="submit" class="text-gray-400 hover:text-white transition">
                                Cerrar sesión
                            </button>
                        </form>
                    @endauth
                </div>
            </div>
        </div>
    </header>

    @auth
        @include('admin._subnav')
    @endauth

    {{-- Content --}}
    <main class="flex-1 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 w-full">
        {{ $slot }}
    </main>

    {{-- Footer --}}
    <footer class="bg-gray-900 text-gray-500 text-xs py-3">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
            Panel de administración — Solo acceso autorizado
        </div>
    </footer>

    @stack('scripts')
</body>
</html>
