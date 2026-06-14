<x-layouts.app title="Radiografía de la contratación pública por provincia — Contratación Abierta">

    <div class="max-w-5xl mx-auto">
        <h1 class="text-2xl font-bold text-gray-900 mb-2">Radiografía por provincia</h1>
        <p class="text-gray-600 mb-8 max-w-3xl">
            ¿Cuánto dinero público se adjudica en tu provincia y a quién? Elige una provincia para ver el
            gasto contratado, el importe por habitante, los principales adjudicatarios y organismos, y su
            evolución. Datos de fuentes oficiales (PLACSP y portales de datos abiertos).
        </p>

        @foreach($provincias as $comunidad => $lista)
        <section class="mb-8">
            <h2 class="text-sm font-semibold text-primary uppercase tracking-wide mb-3 border-b border-gray-200 pb-1">
                {{ $comunidad ?? 'Otras' }}
            </h2>
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
                @foreach($lista as $p)
                <a href="{{ route('radiografia.show', $p['slug']) }}"
                   class="block bg-white rounded-lg shadow hover:shadow-md transition p-3 group">
                    <div class="font-medium text-gray-900 group-hover:text-primary">{{ $p['nombre'] }}</div>
                    @if(!empty($p['poblacion']))
                    <div class="text-xs text-gray-500 mt-0.5">{{ number_format($p['poblacion'], 0, ',', '.') }} hab.</div>
                    @endif
                </a>
                @endforeach
            </div>
        </section>
        @endforeach
    </div>

</x-layouts.app>
