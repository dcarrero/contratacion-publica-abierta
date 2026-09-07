@php
    $totales = $serie->totales();
    $mejorAnio = $serie->mejorAnio();
    $mejorMes = $serie->mejorMes();
    $ultimoMes = $serie->ultimoMesConDatos();
    $primerAnio = $serie->primerAnio();

    $metaDesc = 'Histórico de contratación pública de '.$comunidad->nombre.': '
        .number_format($totales['num'], 0, ',', '.').' contratos por '.formatImporteCorto($totales['importe'])
        .($primerAnio ? ' desde '.$primerAnio : '').'. Evolución año a año y comparativa mes a mes.';
@endphp
<x-layouts.app :title="'Histórico de ' . $comunidad->nombre . ' — Contratación Abierta'" :metaDescription="$metaDesc">

    <x-seo.breadcrumb :items="[
        ['name' => 'Administraciones', 'url' => route('administraciones.index')],
        ['name' => $comunidad->nombre, 'url' => route('administraciones.show', $comunidad->nuts)],
        ['name' => 'Histórico', 'url' => route('administraciones.historico', $comunidad->nuts)],
    ]" />

    {{-- Breadcrumb --}}
    <nav class="text-sm text-gray-500 mb-4">
        <a href="{{ route('administraciones.index') }}" class="hover:text-primary">Administraciones</a>
        <span class="mx-1">/</span>
        <a href="{{ route('administraciones.show', $comunidad->nuts) }}" class="hover:text-primary">{{ $comunidad->nombre }}</a>
        <span class="mx-1">/</span>
        <span class="text-gray-700">Histórico</span>
    </nav>

    <x-ui.cabecera :titulo="'Histórico de '.$comunidad->nombre">
        <x-slot:meta>
            <span>NUTS {{ $comunidad->nuts }}</span>
            @if($primerAnio)
                <span>{{ $primerAnio }}–{{ $serie->ultimoAnio() }}</span>
            @endif
            <a href="{{ route('administraciones.show', $comunidad->nuts) }}" class="text-primary hover:underline">volver a la ficha</a>
        </x-slot:meta>
    </x-ui.cabecera>

    @if($serie->estaVacia())
        <x-ui.estado-vacio titulo="Sin datos históricos">
            Esta comunidad no tiene contratos con fecha de publicación.
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
                    etiqueta="Gasto por habitante"
                    :valor="$comunidad->poblacion > 0 ? formatImporte($totales['importe'] / $comunidad->poblacion) : '—'"
                    delta-abs="acumulado, población INE" />
            </x-slot:kpis>

            <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500">Comparativa mes a mes</h3>
            <div class="mt-3">
                <x-serie.grafico-mensual id="adminHistGraficoMensual" :series="$seriesComparadas" height="320px" />
            </div>
            <x-ui.nota class="mt-3">
                Se comparan los dos últimos años con actividad. Para ver un año concreto en detalle,
                pulsa sobre él en la tabla o en la matriz.
            </x-ui.nota>
        </x-ui.panel>

        {{-- Tabla año a año --}}
        <x-ui.seccion titulo="Contratación año a año">
            <x-serie.tabla-anios
                :anios="$anios"
                ruta-anio="administraciones.anio"
                :ruta-param="$comunidad->nuts"
                :titulo="'Contratación anual de '.$comunidad->nombre" />
        </x-ui.seccion>

        {{-- Matriz año × mes --}}
        <x-ui.seccion
            titulo="Actividad por mes y año"
            descripcion="Cada celda es un mes. El color indica el importe adjudicado y la cifra, el número de contratos.">
            <x-serie.heatmap
                :matriz="$matriz"
                metrica="importe"
                ruta-anio="administraciones.anio"
                :ruta-param="$comunidad->nuts" />
        </x-ui.seccion>

        {{-- Récords y estructura --}}
        <x-ui.seccion titulo="Récords">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <x-ui.dato etiqueta="Mejor mes">
                    <p class="text-lg font-bold text-gray-900">{{ $mejorMes['etiqueta'] }} {{ $mejorMes['anio'] }}</p>
                    <p class="mt-1 text-sm text-gray-500">
                        {{ number_format($mejorMes['num'], 0, ',', '.') }} contratos ·
                        <span class="font-medium text-primary">{{ formatImporteCorto($mejorMes['importe']) }}</span>
                    </p>
                </x-ui.dato>
                <x-ui.dato etiqueta="Última actividad">
                    <p class="text-lg font-bold text-gray-900">{{ $ultimoMes['etiqueta'] }} {{ $ultimoMes['anio'] }}</p>
                    <p class="mt-1 text-sm text-gray-500">{{ number_format($ultimoMes['num'], 0, ',', '.') }} contratos</p>
                </x-ui.dato>
                <x-ui.dato etiqueta="Organismos y empresas">
                    <p class="text-lg font-bold text-gray-900">
                        {{ $total_organismos !== null ? number_format($total_organismos, 0, ',', '.') : '—' }}
                        <span class="text-sm font-normal text-gray-500">organismos</span>
                    </p>
                    <p class="mt-1 text-sm text-gray-500">
                        {{ $total_adjudicatarios !== null ? number_format($total_adjudicatarios, 0, ',', '.') : '—' }} adjudicatarios distintos
                    </p>
                </x-ui.dato>
            </div>
        </x-ui.seccion>

        {{-- Top organismos histórico --}}
        <x-ui.seccion titulo="Organismos con más volumen (histórico)">
            <x-ui.tarjeta :padding="false">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <caption class="sr-only">Organismos con más importe adjudicado de {{ $comunidad->nombre }}</caption>
                        <thead class="bg-gray-50 text-gray-600 uppercase text-xs">
                            <tr>
                                <th scope="col" class="px-4 py-3 text-left">Nombre</th>
                                <th scope="col" class="px-4 py-3 text-right">Contratos</th>
                                <th scope="col" class="px-4 py-3 text-right">Importe</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach($topOrganismos as $org)
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-3">
                                    <a href="{{ route('organismos.show', $org->nif) }}" class="text-gray-900 hover:text-primary hover:underline">
                                        {{ Str::limit($org->nombre, 60) }}
                                    </a>
                                </td>
                                <td class="px-4 py-3 text-right text-gray-500">{{ number_format($org->total_contratos, 0, ',', '.') }}</td>
                                <td class="px-4 py-3 text-right font-medium whitespace-nowrap">{{ formatImporteCorto($org->total_importe) }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-ui.tarjeta>
            <x-ui.nota class="mt-3">
                El volumen de cada organismo es el de toda su actividad, no sólo la de esta comunidad.
            </x-ui.nota>
        </x-ui.seccion>

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
