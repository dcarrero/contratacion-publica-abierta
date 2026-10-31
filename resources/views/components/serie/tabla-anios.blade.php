@props([
    'anios',            // salida de SerieTemporal::anios()
    'rutaAnio' => null, // nombre de ruta para enlazar cada año
    'rutaParam' => null,// parámetro base de esa ruta (nif, nuts…)
    'titulo' => 'Contratación por año',
])
@php
    $maxImporte = collect($anios)->max('importe') ?: 1;

    // Antes de la Ley 9/2017 no había obligación de publicar en PLACSP: esos años tienen
    // una cobertura mínima y, mezclados con el resto, se leen como una caída de actividad
    // que nunca existió. Se muestran aparte, no se ocultan.
    $corte = (int) config('contratacion.anio_cobertura_completa', 2018);
    $recientes = array_values(array_filter($anios, fn ($f) => $f['anio'] >= $corte));
    $antiguos = array_values(array_filter($anios, fn ($f) => $f['anio'] < $corte));
    $totalAntiguos = [
        'num' => array_sum(array_column($antiguos, 'num')),
        'importe' => array_sum(array_column($antiguos, 'importe')),
    ];
@endphp
<x-ui.tarjeta :padding="false">
    <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
            <caption class="sr-only">{{ $titulo }}</caption>
            <thead class="bg-gray-50 text-gray-600 uppercase text-xs">
                <tr>
                    <th scope="col" class="px-4 py-3 text-left">Año</th>
                    <th scope="col" class="px-4 py-3 text-right">Contratos</th>
                    <th scope="col" class="px-4 py-3 text-right">Importe</th>
                    <th scope="col" class="px-4 py-3 text-right">Variación</th>
                    <th scope="col" class="px-4 py-3 text-right hidden sm:table-cell">% del total</th>
                    <th scope="col" class="px-4 py-3 text-left hidden md:table-cell w-40">Peso</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach($recientes as $fila)
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 font-medium text-gray-900">
                        @if($rutaAnio)
                            <a href="{{ route($rutaAnio, [$rutaParam, $fila['anio']]) }}" class="hover:text-primary hover:underline">{{ $fila['anio'] }}</a>
                        @else
                            {{ $fila['anio'] }}
                        @endif
                    </td>
                    <td class="px-4 py-3 text-right">{{ number_format($fila['num'], 0, ',', '.') }}</td>
                    <td class="px-4 py-3 text-right font-medium whitespace-nowrap">{{ formatImporteCorto($fila['importe']) }}</td>
                    <td class="px-4 py-3 text-right whitespace-nowrap">
                        @if($fila['variacion_importe'] === null)
                            <span class="text-gray-300" title="Sin año anterior con el que comparar">—</span>
                        @else
                            <span class="{{ $fila['variacion_importe'] > 0 ? 'text-green-700' : ($fila['variacion_importe'] < 0 ? 'text-red-700' : 'text-gray-500') }}">
                                {{ $fila['variacion_importe'] > 0 ? '+' : '' }}{{ number_format($fila['variacion_importe'], 1, ',', '.') }}%
                            </span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-right text-gray-500 hidden sm:table-cell">{{ number_format($fila['pct_total_importe'], 1, ',', '.') }}%</td>
                    <td class="px-4 py-3 hidden md:table-cell">
                        <div class="h-2 bg-gray-100 rounded-full overflow-hidden">
                            <div class="h-full bg-primary rounded-full" style="width: {{ round($fila['importe'] / $maxImporte * 100, 1) }}%"></div>
                        </div>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @if($antiguos)
        <details class="border-t border-gray-100 group">
            <summary class="px-4 py-3 text-sm text-gray-600 cursor-pointer hover:bg-gray-50 marker:content-['']">
                <span class="font-medium text-gray-700">{{ count($antiguos) }} {{ count($antiguos) === 1 ? 'año anterior' : 'años anteriores' }} a {{ $corte }}</span>
                <span class="text-gray-500">
                    · {{ number_format($totalAntiguos['num'], 0, ',', '.') }} contratos ·
                    {{ formatImporteCorto($totalAntiguos['importe']) }}
                </span>
                <span class="ml-1 text-primary underline underline-offset-2">
                    <span class="group-open:hidden">mostrar</span>
                    <span class="hidden group-open:inline">ocultar</span>
                </span>
            </summary>

            <div class="overflow-x-auto border-t border-gray-100">
                <table class="min-w-full text-sm">
                    <caption class="sr-only">{{ $titulo }}: años anteriores a {{ $corte }}</caption>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($antiguos as $fila)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 font-medium text-gray-900">
                                @if($rutaAnio)
                                    <a href="{{ route($rutaAnio, [$rutaParam, $fila['anio']]) }}" class="hover:text-primary hover:underline">{{ $fila['anio'] }}</a>
                                @else
                                    {{ $fila['anio'] }}
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right">{{ number_format($fila['num'], 0, ',', '.') }}</td>
                            <td class="px-4 py-3 text-right font-medium whitespace-nowrap">{{ formatImporteCorto($fila['importe']) }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <p class="px-4 py-3 text-xs text-gray-500 border-t border-gray-100">
                Hasta la Ley 9/2017 de Contratos del Sector Público, en vigor desde marzo de
                {{ $corte }}, no existía obligación general de publicar en la Plataforma de
                Contratación. Estos años reflejan lo que unas pocas administraciones publicaban
                voluntariamente, no toda la contratación de entonces.
            </p>
        </details>
    @endif
</x-ui.tarjeta>
