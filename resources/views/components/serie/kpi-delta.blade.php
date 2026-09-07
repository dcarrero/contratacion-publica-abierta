@props([
    'etiqueta',
    'valor',
    'delta' => null,        // variación % respecto al año anterior; null = no comparable
    'deltaAbs' => null,     // variación absoluta, ya formateada
    'periodo' => null,      // "en ene–ago vs 2025" — qué tramo se compara contra qué año
    'destacado' => false,
    'plano' => false,       // celda dentro del panel principal: sin chrome propio
])
{{-- Tarjeta de KPI con su variación interanual. El delta nulo no se pinta como 0%:
     significa que no hay base de comparación (año anterior sin actividad). --}}
<div @class([
    'bg-white p-5',
    'rounded-lg ring-1 ring-gray-200' => ! $plano,
]) data-cifras>
    <p class="text-xs uppercase tracking-wide text-gray-500">{{ $etiqueta }}</p>
    <p @class([
        'mt-1 font-bold tracking-tight',
        'text-2xl sm:text-3xl text-primary' => $destacado,
        'text-2xl text-gray-900' => ! $destacado,
    ])>{{ $valor }}</p>

    @if($delta !== null)
        @php
            $sube = $delta > 0;
            $plana = abs($delta) < 0.05;
        @endphp
        <p class="mt-2 flex flex-wrap items-center gap-1 text-xs">
            <span @class([
                'inline-flex items-center gap-0.5 rounded px-1.5 py-0.5 font-semibold',
                'bg-gray-100 text-gray-600' => $plana,
                'bg-green-50 text-green-700' => ! $plana && $sube,
                'bg-red-50 text-red-700' => ! $plana && ! $sube,
            ])>
                @unless($plana)
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="{{ $sube ? 'M5 15l7-7 7 7' : 'M19 9l-7 7-7-7' }}" />
                    </svg>
                @endunless
                {{ $plana ? 'sin cambio' : ($sube ? '+' : '').number_format($delta, 1, ',', '.').'%' }}
            </span>
            @if($periodo)
                <span class="text-gray-500">{{ $periodo }}</span>
            @endif
        </p>
    @elseif($deltaAbs !== null)
        <p class="mt-2 text-xs text-gray-500">{{ $deltaAbs }}</p>
    @endif
</div>
