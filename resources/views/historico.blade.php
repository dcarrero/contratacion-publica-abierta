@php
    $totales = $serie->totales();
    $mejorAnio = $serie->mejorAnio();
    $mejorMes = $serie->mejorMes();
    $ultimoMes = $serie->ultimoMesConDatos();
    $primerAnio = $serie->primerAnio();

    $metaDesc = 'Histórico de la contratación pública en España: '
        .number_format($totales['num'], 0, ',', '.').' contratos por '.formatImporteCorto($totales['importe'])
        .($primerAnio ? ' desde '.$primerAnio : '').'. Evolución año a año, comparativa mes a mes y por comunidad autónoma.';
@endphp
<x-layouts.app title="Datos históricos — Contratación Abierta" :metaDescription="$metaDesc">

    <x-seo.breadcrumb :items="[
        ['name' => 'Inicio', 'url' => route('dashboard')],
        ['name' => 'Datos históricos', 'url' => route('historico')],
    ]" />

    {{-- Breadcrumb --}}
    <nav class="text-sm text-gray-500 mb-4">
        <a href="{{ route('dashboard') }}" class="hover:text-primary">Inicio</a>
        <span class="mx-1">/</span>
        <span class="text-gray-700">Datos históricos</span>
    </nav>

    <x-ui.cabecera titulo="Datos históricos de contratación pública">
        <x-slot:meta>
            <span>Toda España</span>
            @if($primerAnio)
                <span>{{ $primerAnio }}–{{ $serie->ultimoAnio() }}</span>
            @endif
        </x-slot:meta>
    </x-ui.cabecera>

    @if($serie->estaVacia())
        <x-ui.estado-vacio titulo="Sin datos históricos">
            Todavía no hay contratos con fecha de publicación.
        </x-ui.estado-vacio>
    @else

    <div class="space-y-10 sm:space-y-12">

        {{-- Bloque principal: el acumulado de toda la serie y su comparativa reciente. --}}
        <x-ui.panel destacado>
            <x-slot:cabecera>
                <h2 class="font-serif text-xl font-semibold tracking-tight text-gray-900 sm:text-2xl">
                    Resumen histórico
                    <span class="font-sans text-sm font-normal text-gray-500">({{ count($anios) }} {{ count($anios) === 1 ? 'año' : 'años' }} con actividad)</span>
                </h2>
            </x-slot:cabecera>

            <x-slot:kpis>
                <x-serie.kpi-delta plano
                    etiqueta="Contratos"
                    :valor="number_format($totales['num'], 0, ',', '.')"
                    :delta-abs="'en '.count($anios).' '.(count($anios) === 1 ? 'año' : 'años')" />

                <x-serie.kpi-delta plano destacado
                    etiqueta="Importe acumulado"
                    :valor="formatImporteCorto($totales['importe'])"
                    :delta-abs="'media anual: '.formatImporteCorto($totales['importe'] / max(count($anios), 1))" />

                <x-serie.kpi-delta plano
                    etiqueta="Año récord"
                    :valor="(string) $mejorAnio['anio']"
                    :delta-abs="formatImporteCorto($mejorAnio['importe'])" />

                <x-serie.kpi-delta plano
                    etiqueta="Última actividad"
                    :valor="$ultimoMes['etiqueta'].' '.$ultimoMes['anio']"
                    :delta-abs="number_format($ultimoMes['num'], 0, ',', '.').' contratos'" />
            </x-slot:kpis>

            <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500">Comparativa mes a mes</h3>
            <div class="mt-3">
                <x-serie.grafico-mensual id="histNacionalMensual" :series="$seriesComparadas" height="320px" />
            </div>
            <x-ui.nota class="mt-3">
                Se comparan los dos últimos años con actividad.
            </x-ui.nota>
        </x-ui.panel>

        {{-- Tabla año a año --}}
        <x-ui.seccion titulo="Contratación año a año">
            <x-serie.tabla-anios :anios="$anios" titulo="Contratación pública anual en España" />
            <x-ui.nota class="mt-3">
                Los primeros años recogen menos fuentes que los recientes: una subida puede reflejar
                mayor cobertura de datos y no sólo más contratación.
            </x-ui.nota>
        </x-ui.seccion>

        {{-- Matriz año × mes --}}
        <x-ui.seccion
            titulo="Actividad por mes y año"
            descripcion="Cada celda es un mes. El color indica el importe adjudicado y la cifra, el número de contratos.">
            <x-serie.heatmap :matriz="$matriz" metrica="importe" />
        </x-ui.seccion>

        {{-- Récords --}}
        <x-ui.seccion titulo="Récords">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <x-ui.dato etiqueta="Mejor mes">
                    <p class="text-lg font-bold text-gray-900">{{ $mejorMes['etiqueta'] }} {{ $mejorMes['anio'] }}</p>
                    <p class="mt-1 text-sm text-gray-500">
                        {{ number_format($mejorMes['num'], 0, ',', '.') }} contratos ·
                        <span class="font-medium text-primary">{{ formatImporteCorto($mejorMes['importe']) }}</span>
                    </p>
                </x-ui.dato>
                <x-ui.dato etiqueta="Año con más contratos">
                    @php $anioMasContratos = collect($anios)->sortByDesc('num')->first(); @endphp
                    <p class="text-lg font-bold text-gray-900">{{ $anioMasContratos['anio'] }}</p>
                    <p class="mt-1 text-sm text-gray-500">{{ number_format($anioMasContratos['num'], 0, ',', '.') }} contratos</p>
                </x-ui.dato>
            </div>
        </x-ui.seccion>

        {{-- Históricos por comunidad --}}
        @if($distribucionCcaa)
        <x-ui.seccion titulo="Histórico por comunidad autónoma">
            <x-ui.tarjeta :padding="false">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <caption class="sr-only">Contratación acumulada por comunidad autónoma</caption>
                        <thead class="bg-gray-50 text-gray-600 uppercase text-xs">
                            <tr>
                                <th scope="col" class="px-4 py-3 text-left">Comunidad</th>
                                <th scope="col" class="px-4 py-3 text-right">Contratos</th>
                                <th scope="col" class="px-4 py-3 text-right">Importe</th>
                                <th scope="col" class="px-4 py-3 text-right hidden sm:table-cell">Por habitante</th>
                                <th scope="col" class="px-4 py-3 text-right">Histórico</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach($distribucionCcaa as $ccaa)
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-3">
                                    <a href="{{ route('administraciones.show', $ccaa['nuts']) }}" class="text-gray-900 hover:text-primary hover:underline">
                                        {{ $ccaa['nombre'] }}
                                    </a>
                                </td>
                                <td class="px-4 py-3 text-right">{{ number_format($ccaa['total_contratos'], 0, ',', '.') }}</td>
                                <td class="px-4 py-3 text-right font-medium whitespace-nowrap">{{ formatImporteCorto($ccaa['total_importe']) }}</td>
                                <td class="px-4 py-3 text-right text-gray-500 hidden sm:table-cell">
                                    {{ $ccaa['gasto_per_capita'] ? formatImporte((float) $ccaa['gasto_per_capita']) : '—' }}
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <a href="{{ route('administraciones.historico', $ccaa['nuts']) }}" class="text-primary hover:underline text-xs font-medium">
                                        Ver →
                                    </a>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-ui.tarjeta>
        </x-ui.seccion>
        @endif

        @if($serie->sinFecha > 0)
            <x-ui.nota>
                {{ number_format($serie->sinFecha, 0, ',', '.') }}
                {{ $serie->sinFecha === 1 ? 'contrato no tiene' : 'contratos no tienen' }} fecha de publicación
                y {{ $serie->sinFecha === 1 ? 'queda excluido' : 'quedan excluidos' }} de las series por año y mes.
            </x-ui.nota>
        @endif
    </div>

    @endif

</x-layouts.app>
