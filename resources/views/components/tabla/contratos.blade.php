@props([
    'contratos',
    'caption',
    'contraparte' => null,          // relación a pintar: 'organismo' | 'adjudicatario'; null = sin columna
    'contraparteEtiqueta' => null,  // cabecera de la columna y etiqueta en la tarjeta
    'contraparteRuta' => null,      // ruta con la que enlazarla; null = solo texto
    'contraparteLimite' => 40,      // 0 = sin truncar (null no vale: @props lo sustituye por el defecto)
    'contraparteSoloMd' => true,    // en la tabla, oculta la columna por debajo de md
    'objetoLimite' => 80,
    'importeCorto' => true,         // formatImporteCorto() vs formatImporte()
    'mostrarMenor' => false,
    'mostrarPlacsp' => false,
    'vacio' => null,                // texto a mostrar cuando no hay filas; null = no pintar nada
])

@php
    // Las tres fechas no son excluyentes y el orden de preferencia es el mismo en
    // todas las vistas: publicación > adjudicación > formalización.
    $fechaDe = fn ($contrato) => formatFecha(
        $contrato->fecha_publicacion ?? $contrato->fecha_adjudicacion ?? $contrato->fecha_formalizacion
    );
    $importeDe = fn ($contrato) => $importeCorto
        ? formatImporteCorto($contrato->importe_adjudicacion)
        : formatImporte($contrato->importe_adjudicacion);
    $contraparteDe = fn ($contrato) => $contraparte ? $contrato->{$contraparte} : null;
    $nombreDe = fn ($parte) => $contraparteLimite > 0 ? Str::limit($parte->nombre, $contraparteLimite) : $parte->nombre;

    $columnas = 3 + ($contraparte ? 1 : 0) + ($mostrarPlacsp ? 1 : 0);
@endphp

{{-- Dos representaciones de los mismos datos: tabla a partir de sm y lista de tarjetas
     en móvil. Una fila de contrato no cabe en el ancho de un teléfono, y el scroll
     horizontal obliga a leer cada fila a trozos. La versión móvil es una lista real
     (ul/li + dl), no una tabla con roles falsos, para que el lector de pantalla la
     anuncie como lo que es. --}}
<div class="hidden sm:block overflow-x-auto">
    <table class="min-w-full text-sm">
        <caption class="sr-only">{{ $caption }}</caption>
        <thead class="bg-gray-50 text-gray-600 uppercase text-xs">
            <tr>
                <th scope="col" class="px-4 py-3 text-left">Fecha</th>
                <th scope="col" class="px-4 py-3 text-left">Objeto</th>
                @if($contraparte)
                    <th scope="col" @class(['px-4 py-3 text-left', 'hidden md:table-cell' => $contraparteSoloMd])>{{ $contraparteEtiqueta }}</th>
                @endif
                <th scope="col" class="px-4 py-3 text-right">Importe</th>
                @if($mostrarPlacsp)
                    <th scope="col" class="px-4 py-3 text-center">PLACSP</th>
                @endif
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse($contratos as $contrato)
                @php($parte = $contraparteDe($contrato))
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 text-gray-500 text-xs whitespace-nowrap">{{ $fechaDe($contrato) }}</td>
                    <td class="px-4 py-3 max-w-md">
                        <a href="{{ route('contratos.show', $contrato->placsp_id) }}"
                           title="{{ $contrato->objeto }}"
                           class="text-gray-900 hover:text-primary hover:underline">
                            {{ Str::limit($contrato->objeto, $objetoLimite) }}
                        </a>
                        @if($mostrarMenor && $contrato->es_menor)
                            <span class="ml-1 inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-amber-100 text-amber-700">Menor</span>
                        @endif
                    </td>
                    @if($contraparte)
                        <td @class(['px-4 py-3 text-gray-600 text-xs max-w-xs truncate', 'hidden md:table-cell' => $contraparteSoloMd])>
                            @if($parte && $contraparteRuta)
                                <a href="{{ route($contraparteRuta, $parte->nif) }}" class="hover:text-primary hover:underline">
                                    {{ $nombreDe($parte) }}
                                </a>
                            @elseif($parte)
                                {{ $nombreDe($parte) }}
                            @else
                                —
                            @endif
                        </td>
                    @endif
                    <td class="px-4 py-3 text-right font-medium whitespace-nowrap">{{ $importeDe($contrato) }}</td>
                    @if($mostrarPlacsp)
                        <td class="px-4 py-3 text-center">
                            @if($contrato->url_placsp)
                                <a href="{{ $contrato->url_placsp }}" target="_blank" rel="noopener"
                                   class="text-primary hover:text-primary-oscuro underline text-xs">
                                    Ver<span class="sr-only"> el contrato «{{ Str::limit($contrato->objeto, 60) }}» en PLACSP</span>
                                </a>
                            @else
                                —
                            @endif
                        </td>
                    @endif
                </tr>
            @empty
                @if($vacio)
                    <tr><td colspan="{{ $columnas }}" class="px-4 py-6 text-center text-gray-500">{{ $vacio }}</td></tr>
                @endif
            @endforelse
        </tbody>
    </table>
</div>

<ul class="divide-y divide-gray-100 sm:hidden" aria-label="{{ $caption }}">
    @forelse($contratos as $contrato)
        @php($parte = $contraparteDe($contrato))
        <li class="px-4 py-3">
            <a href="{{ route('contratos.show', $contrato->placsp_id) }}"
               class="block text-sm font-medium text-gray-900 hover:text-primary hover:underline">
                {{ Str::limit($contrato->objeto, $objetoLimite) }}
            </a>
            @if($mostrarMenor && $contrato->es_menor)
                <span class="mt-1 inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-amber-100 text-amber-700">Menor</span>
            @endif

            <dl class="mt-2 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-xs">
                <dt class="text-gray-500">Fecha</dt>
                <dd class="text-right text-gray-700" data-cifras>{{ $fechaDe($contrato) }}</dd>

                @if($contraparte)
                    <dt class="text-gray-500">{{ $contraparteEtiqueta }}</dt>
                    <dd class="text-right text-gray-700">
                        @if($parte && $contraparteRuta)
                            <a href="{{ route($contraparteRuta, $parte->nif) }}" class="hover:text-primary hover:underline">
                                {{ $nombreDe($parte) }}
                            </a>
                        @elseif($parte)
                            {{ $nombreDe($parte) }}
                        @else
                            —
                        @endif
                    </dd>
                @endif

                <dt class="text-gray-500">Importe</dt>
                <dd class="text-right font-semibold text-gray-900" data-cifras>{{ $importeDe($contrato) }}</dd>
            </dl>

            @if($mostrarPlacsp && $contrato->url_placsp)
                <a href="{{ $contrato->url_placsp }}" target="_blank" rel="noopener"
                   class="mt-2 inline-block text-xs text-primary hover:text-primary-oscuro underline">
                    Ver en PLACSP<span class="sr-only">: {{ Str::limit($contrato->objeto, 60) }}</span>
                </a>
            @endif
        </li>
    @empty
        @if($vacio)
            <li class="px-4 py-6 text-center text-sm text-gray-500">{{ $vacio }}</li>
        @endif
    @endforelse
</ul>
