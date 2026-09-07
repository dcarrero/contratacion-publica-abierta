@props([
    'serie',            // App\Support\SerieTemporal
    'urlHistorico',
])
@php
    $totales = $serie->totales();
    $mejorAnio = $serie->mejorAnio();
    $primerAnio = $serie->primerAnio();
@endphp
{{-- Resumen del histórico en la ficha: mantiene las cifras acumuladas a la vista (y en la
     URL canónica, por SEO) sin ocupar la vista principal con 18 años de datos. Es una
     tira de contexto, no un bloque de datos: pesa menos que el panel del año. --}}
<section {{ $attributes }}>
    <x-ui.tarjeta :padding="false" class="px-5 py-4">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center">
            <div class="flex flex-1 flex-wrap gap-x-8 gap-y-3 text-sm" data-cifras>
                <div>
                    <p class="text-xs uppercase tracking-wide text-gray-500">Desde {{ $primerAnio }}</p>
                    <p class="mt-0.5 font-bold text-gray-900">{{ number_format($totales['num'], 0, ',', '.') }} contratos</p>
                </div>
                <div>
                    <p class="text-xs uppercase tracking-wide text-gray-500">Importe acumulado</p>
                    <p class="mt-0.5 font-bold text-gray-900">{{ formatImporteCorto($totales['importe']) }}</p>
                </div>
                @if($mejorAnio)
                <div>
                    <p class="text-xs uppercase tracking-wide text-gray-500">Año récord</p>
                    <p class="mt-0.5 font-bold text-gray-900">{{ $mejorAnio['anio'] }} · {{ formatImporteCorto($mejorAnio['importe']) }}</p>
                </div>
                @endif
            </div>
            <a href="{{ $urlHistorico }}"
               class="inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-lg px-4 py-2 text-sm font-semibold text-primary ring-1 ring-primary/40 transition hover:bg-red-50">
                Ver histórico completo
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                </svg>
            </a>
        </div>
    </x-ui.tarjeta>

    @if($serie->sinFecha > 0)
        <x-ui.nota class="mt-2">
            {{ number_format($serie->sinFecha, 0, ',', '.') }}
            {{ $serie->sinFecha === 1 ? 'contrato no tiene' : 'contratos no tienen' }} fecha de publicación
            y {{ $serie->sinFecha === 1 ? 'queda excluido' : 'quedan excluidos' }} de las series temporales.
        </x-ui.nota>
    @endif
</section>
