@props([
    'anios',            // salida de SerieTemporal::anios()
    'rutaAnio' => null, // nombre de ruta para enlazar cada año
    'rutaParam' => null,// parámetro base de esa ruta (nif, nuts…)
    'titulo' => 'Contratación por año',
])
@php
    $maxImporte = collect($anios)->max('importe') ?: 1;
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
                @foreach($anios as $fila)
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
</x-ui.tarjeta>
