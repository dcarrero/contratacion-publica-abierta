<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ?? 'Contratación Abierta' }}</title>
    <meta name="description" content="{{ $metaDescription ?? 'Portal de transparencia en contratación pública de España. Contratos de administraciones públicas estatales, autonómicas y locales.' }}">

    <meta property="og:title" content="{{ $title ?? 'Contratación Abierta' }}">
    <meta property="og:description" content="{{ $metaDescription ?? 'Portal de transparencia en contratación pública de España.' }}">
    <meta property="og:type" content="website">
    <meta property="og:locale" content="es_ES">

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700" rel="stylesheet">

    <link rel="alternate" type="application/rss+xml" title="Contratación Abierta — Últimos contratos" href="{{ route('rss.contratos') }}">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('head')
    @stack('styles')
</head>
<body class="min-h-screen flex flex-col bg-gray-50 text-gray-800 antialiased">

    {{-- Header --}}
    <header class="bg-primary text-white shadow-md">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16">
                <div class="flex items-center gap-3">
                    <a href="{{ route('dashboard') }}" class="font-bold text-lg sm:text-xl tracking-tight hover:opacity-90">
                        Contratación Abierta
                    </a>
                    <span class="hidden sm:inline text-red-200 text-sm">España</span>
                </div>

                {{-- Nav desktop --}}
                <nav class="hidden md:flex items-center gap-6 text-sm font-medium">
                    <a href="{{ route('dashboard') }}" class="hover:text-red-200 transition {{ request()->routeIs('dashboard') ? 'text-white underline underline-offset-4' : 'text-red-100' }}">
                        Inicio
                    </a>
                    <a href="{{ route('contratos.index') }}" class="hover:text-red-200 transition {{ request()->routeIs('contratos.*') ? 'text-white underline underline-offset-4' : 'text-red-100' }}">
                        Contratos
                    </a>
                    <a href="{{ route('administraciones.index') }}" class="hover:text-red-200 transition {{ request()->routeIs('administraciones.*') ? 'text-white underline underline-offset-4' : 'text-red-100' }}">
                        Administraciones
                    </a>
                    <a href="{{ route('mapa') }}" class="hover:text-red-200 transition {{ request()->routeIs('mapa') ? 'text-white underline underline-offset-4' : 'text-red-100' }}">
                        Mapa
                    </a>
                    <a href="{{ route('analisis') }}" class="hover:text-red-200 transition {{ request()->routeIs('analisis') ? 'text-white underline underline-offset-4' : 'text-red-100' }}">
                        Análisis
                    </a>
                    <a href="{{ route('organismos.index') }}" class="hover:text-red-200 transition {{ request()->routeIs('organismos.*') ? 'text-white underline underline-offset-4' : 'text-red-100' }}">
                        Organismos
                    </a>
                    <a href="{{ route('empresas.index') }}" class="hover:text-red-200 transition {{ request()->routeIs('empresas.*') ? 'text-white underline underline-offset-4' : 'text-red-100' }}">
                        Adjudicatarios
                    </a>
                    <a href="{{ route('sobre') }}" class="hover:text-red-200 transition {{ request()->routeIs('sobre') ? 'text-white underline underline-offset-4' : 'text-red-100' }}">
                        Sobre
                    </a>
                </nav>

                {{-- Hamburger mobile --}}
                <button
                    x-data
                    x-on:click="$dispatch('toggle-mobile-nav')"
                    class="md:hidden p-2 rounded hover:bg-primary-dark transition"
                    aria-label="Menú"
                >
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                    </svg>
                </button>
            </div>
        </div>

        {{-- Nav mobile --}}
        <nav
            x-data="{ open: false }"
            x-on:toggle-mobile-nav.window="open = !open"
            x-show="open"
            x-transition
            class="md:hidden border-t border-red-400"
        >
            <div class="px-4 py-3 space-y-2">
                <a href="{{ route('dashboard') }}" class="block px-3 py-2 rounded hover:bg-primary-dark transition text-sm">Inicio</a>
                <a href="{{ route('contratos.index') }}" class="block px-3 py-2 rounded hover:bg-primary-dark transition text-sm">Contratos</a>
                <a href="{{ route('administraciones.index') }}" class="block px-3 py-2 rounded hover:bg-primary-dark transition text-sm">Administraciones</a>
                <a href="{{ route('mapa') }}" class="block px-3 py-2 rounded hover:bg-primary-dark transition text-sm">Mapa</a>
                <a href="{{ route('analisis') }}" class="block px-3 py-2 rounded hover:bg-primary-dark transition text-sm">Análisis</a>
                <a href="{{ route('organismos.index') }}" class="block px-3 py-2 rounded hover:bg-primary-dark transition text-sm">Organismos</a>
                <a href="{{ route('empresas.index') }}" class="block px-3 py-2 rounded hover:bg-primary-dark transition text-sm">Adjudicatarios</a>
                <a href="{{ route('sobre') }}" class="block px-3 py-2 rounded hover:bg-primary-dark transition text-sm">Sobre</a>
            </div>
        </nav>
    </header>

    {{-- Content --}}
    <main class="flex-1 {{ ($fullWidth ?? false) ? '' : 'max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8' }} w-full">
        {{ $slot }}
    </main>

    {{-- Footer --}}
    <footer class="bg-gray-800 text-gray-400 text-sm">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-3">
            <div class="sm:flex sm:items-center sm:justify-between">
                <p>
                    Proyecto de transparencia ciudadana. No es una web oficial.
                </p>
                <p class="mt-2 sm:mt-0 flex gap-4">
                    <a href="{{ route('sobre') }}" class="text-gray-300 hover:text-white underline">
                        Fuentes de datos
                    </a>
                    <a href="{{ route('aviso-legal') }}" class="text-gray-300 hover:text-white underline">
                        Aviso legal
                    </a>
                    <a href="{{ route('rss.contratos') }}" class="text-gray-300 hover:text-white underline" title="Feed RSS">
                        RSS
                    </a>
                    <a href="https://github.com/dcarrero/contratacion-publica-clm-es" target="_blank" rel="noopener" class="text-gray-300 hover:text-white underline">
                        GitHub
                    </a>
                </p>
            </div>
            <p class="text-gray-500 text-xs leading-relaxed border-t border-gray-700 pt-3">
                Datos extraidos automaticamente de plataformas de transparencia y fuentes de datos oficiales.
                Pueden existir errores o inconsistencias en los datos. Ante cualquier duda,
                consulte las <a href="{{ route('sobre') }}" class="text-gray-400 hover:text-gray-300 underline">fuentes oficiales</a> de cada registro.
            </p>
        </div>
    </footer>

    @stack('scripts')
</body>
</html>
