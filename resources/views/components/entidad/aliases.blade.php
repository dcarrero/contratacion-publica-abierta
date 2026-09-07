@props([
    'aliases',
    'visibles' => 3,   // cuántas variantes se ven sin desplegar
])
@php
    $total = $aliases->count();
    $destacadas = $aliases->take($visibles);
    $restantes = $total - $destacadas->count();
@endphp
@if($total > 0)
    {{-- Hay adjudicatarios con cientos de variantes de nombre (hasta 746): listarlas todas
         empujaba el contenido de la ficha fuera de la primera pantalla. Se muestran las más
         frecuentes y el resto queda a un clic, con <details> para no depender de JS. --}}
    <div class="bg-amber-50 border border-amber-200 rounded-lg mb-6 text-sm">
        @if($restantes <= 0)
            <p class="px-4 py-3 text-amber-800">
                <span class="font-medium">Este adjudicatario aparece también como:</span>
                @foreach($destacadas as $alias)
                    <span class="text-amber-700">{{ $alias->nombre_variante }} ({{ $alias->veces_visto }})</span>@if(! $loop->last), @endif
                @endforeach
            </p>
        @else
            <details class="group">
                <summary class="px-4 py-3 cursor-pointer text-amber-800 marker:content-['']">
                    <span class="font-medium">Este adjudicatario aparece también como:</span>
                    <span class="group-open:hidden">
                        @foreach($destacadas as $alias)
                            <span class="text-amber-700">{{ $alias->nombre_variante }} ({{ $alias->veces_visto }})</span>@if(! $loop->last), @endif
                        @endforeach
                    </span>
                    <span class="ml-1 font-medium text-amber-900 underline underline-offset-2">
                        <span class="group-open:hidden">y {{ number_format($restantes, 0, ',', '.') }} variantes más</span>
                        <span class="hidden group-open:inline">ocultar las {{ number_format($total, 0, ',', '.') }} variantes</span>
                    </span>
                </summary>
                <ul class="px-4 pb-4 pt-1 grid sm:grid-cols-2 lg:grid-cols-3 gap-x-6 gap-y-1 max-h-80 overflow-y-auto">
                    @foreach($aliases as $alias)
                        <li class="text-amber-700 truncate" title="{{ $alias->nombre_variante }}">
                            {{ $alias->nombre_variante }}
                            <span class="text-amber-600/70 text-xs">({{ $alias->veces_visto }})</span>
                        </li>
                    @endforeach
                </ul>
            </details>
        @endif
    </div>
@endif
