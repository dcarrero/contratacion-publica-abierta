@props([
    'matriz',              // salida de SerieTemporal::matrizAnioMes()
    'metrica' => 'importe',
    'rutaAnio' => null,
    'rutaParam' => null,
])
@php
    $max = $metrica === 'importe' ? ($matriz['max_importe'] ?: 1) : ($matriz['max_num'] ?: 1);
    $meses = App\Support\SerieTemporal::MESES_CORTOS;

    // Cinco tramos de intensidad: suficientes para leer el patrón sin convertir la tabla
    // en un degradado indistinguible.
    //
    // La escala es la raíz cuadrada del ratio, no el ratio: los importes de contratación
    // están muy sesgados (un mes récord multiplica por diez al mes típico), y en escala
    // lineal casi todas las celdas caerían en el tramo más bajo, dejando la matriz de un
    // solo color y sin información.
    $tono = function (float $valor) use ($max): string {
        if ($valor <= 0) {
            return 'bg-gray-50 text-gray-300';
        }
        $ratio = sqrt($valor / $max);
        return match (true) {
            $ratio >= 0.75 => 'bg-red-700 text-white',
            $ratio >= 0.50 => 'bg-red-500 text-white',
            $ratio >= 0.25 => 'bg-red-300 text-red-900',
            $ratio >= 0.10 => 'bg-red-200 text-red-900',
            default => 'bg-red-100 text-red-800',
        };
    };
@endphp
<x-ui.tarjeta>
    <div class="overflow-x-auto">
        <table class="min-w-full text-xs border-separate" style="border-spacing: 2px;">
            <caption class="sr-only">
                Contratación mes a mes por año ({{ $metrica === 'importe' ? 'importe adjudicado' : 'número de contratos' }}).
                La intensidad del color es proporcional al valor máximo de la matriz.
            </caption>
            <thead>
                <tr>
                    {{-- Las columnas de año y total se ciñen a su contenido (w-px + nowrap en
                         table-layout automático): sin eso absorbían todo el ancho sobrante y
                         dejaban un vacío entre la etiqueta del año y enero, y entre diciembre
                         y el total. El sobrante se reparte entre los doce meses. --}}
                    <th scope="col" class="w-px px-2 py-1 text-left text-gray-500 font-medium">Año</th>
                    @foreach($meses as $etiqueta)
                        <th scope="col" class="px-1 py-1 text-center text-gray-500 font-medium min-w-10">{{ $etiqueta }}</th>
                    @endforeach
                    <th scope="col" class="w-px whitespace-nowrap px-2 py-1 text-right text-gray-500 font-medium">Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach($matriz['filas'] as $fila)
                <tr>
                    <th scope="row" class="px-2 py-1 text-left font-semibold text-gray-900 whitespace-nowrap">
                        @if($rutaAnio)
                            <a href="{{ route($rutaAnio, [$rutaParam, $fila['anio']]) }}" class="hover:text-primary hover:underline">{{ $fila['anio'] }}</a>
                        @else
                            {{ $fila['anio'] }}
                        @endif
                    </th>
                    @foreach($fila['meses'] as $mes)
                        @php $valor = $metrica === 'importe' ? $mes['importe'] : (float) $mes['num']; @endphp
                        @if($mes['futuro'])
                            <td class="px-1 py-1.5 text-center rounded bg-white border border-dashed border-gray-200 text-gray-300" aria-label="{{ $mes['etiqueta'] }} {{ $fila['anio'] }}: sin datos todavía">·</td>
                        @else
                            <td class="px-1 py-1.5 text-center rounded {{ $tono($valor) }} {{ $mes['parcial'] ? 'ring-1 ring-inset ring-gray-900/30' : '' }}"
                                title="{{ $mes['etiqueta'] }} {{ $fila['anio'] }}: {{ number_format($mes['num'], 0, ',', '.') }} contratos · {{ formatImporteCorto($mes['importe']) }}{{ $mes['parcial'] ? ' (mes en curso)' : '' }}">
                                {{ $mes['num'] > 0 ? number_format($mes['num'], 0, ',', '.') : '' }}
                            </td>
                        @endif
                    @endforeach
                    <td class="px-2 py-1 text-right font-medium text-gray-900 whitespace-nowrap">{{ formatImporteCorto($fila['total']['importe']) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="mt-4 flex flex-wrap items-center gap-3 text-xs text-gray-500">
        <span>Menos</span>
        <span class="flex gap-1">
            <span class="w-5 h-4 rounded bg-red-100 ring-1 ring-inset ring-gray-200 inline-block"></span>
            <span class="w-5 h-4 rounded bg-red-200 inline-block"></span>
            <span class="w-5 h-4 rounded bg-red-300 inline-block"></span>
            <span class="w-5 h-4 rounded bg-red-500 inline-block"></span>
            <span class="w-5 h-4 rounded bg-red-700 inline-block"></span>
        </span>
        <span>Más {{ $metrica === 'importe' ? 'importe' : 'contratos' }}</span>
        <span class="ml-2">La cifra de cada celda es el número de contratos.</span>
    </div>
</x-ui.tarjeta>
