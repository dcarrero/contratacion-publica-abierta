@php
    $totales = $serie->totales();
    $mejorAnio = $serie->mejorAnio();
    $mejorMes = $serie->mejorMes();
    $ultimoMes = $serie->ultimoMesConDatos();
    $primerAnio = $serie->primerAnio();

    $metaDesc = 'Histórico de contratación pública de '.$organismo->nombre.' (NIF '.$organismo->nif.'): '
        .number_format($totales['num'], 0, ',', '.').' contratos por '.formatImporteCorto($totales['importe'])
        .' desde '.$primerAnio.'. Evolución año a año y comparativa mes a mes.';
@endphp
<x-layouts.app :title="'Histórico de ' . $organismo->nombre . ' — Contratación Abierta'" :metaDescription="$metaDesc">

    <x-seo.breadcrumb :items="[
        ['name' => 'Organismos', 'url' => route('organismos.index')],
        ['name' => $organismo->nombre, 'url' => route('organismos.show', $organismo->nif)],
        ['name' => 'Histórico', 'url' => route('organismos.historico', $organismo->nif)],
    ]" />

    {{-- Breadcrumb --}}
    <nav class="text-sm text-gray-500 mb-4">
        <a href="{{ route('organismos.index') }}" class="hover:text-primary">Organismos</a>
        <span class="mx-1">/</span>
        <a href="{{ route('organismos.show', $organismo->nif) }}" class="hover:text-primary">{{ $organismo->nif }}</a>
        <span class="mx-1">/</span>
        <span class="text-gray-700">Histórico</span>
    </nav>

    <x-ui.cabecera :titulo="'Histórico de '.$organismo->nombre">
        <x-slot:meta>
            <span class="font-mono">{{ $organismo->nif }}</span>
            @if($primerAnio)
                <span>{{ $primerAnio }}–{{ $serie->ultimoAnio() }}</span>
            @endif
        </x-slot:meta>
    </x-ui.cabecera>

    <x-entidad.pestanas
        activa="historico"
        :url-ficha="route('organismos.show', $organismo->nif)"
        :url-historico="route('organismos.historico', $organismo->nif)"
        :url-red="route('organismos.red', $organismo->nif)" />

    @if($serie->estaVacia())
        <x-ui.estado-vacio titulo="Sin datos históricos">
            Este organismo no tiene contratos con fecha de publicación.
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
                    :delta-abs="number_format($ultimoMes['num'], 0, ',', '.').' '.($ultimoMes['num'] === 1 ? 'contrato' : 'contratos')" />
            </x-slot:kpis>

            <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500">Comparativa mes a mes</h3>
            <div class="mt-3">
                <x-serie.grafico-mensual id="histGraficoMensual" :series="$seriesComparadas" height="320px" />
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
                ruta-anio="organismos.anio"
                :ruta-param="$organismo->nif"
                :titulo="'Contratación anual de '.$organismo->nombre" />
        </x-ui.seccion>

        {{-- Matriz año × mes --}}
        <x-ui.seccion
            titulo="Actividad por mes y año"
            descripcion="Cada celda es un mes. El color indica el importe adjudicado y la cifra, el número de contratos.">
            <x-serie.heatmap
                :matriz="$matriz"
                metrica="importe"
                ruta-anio="organismos.anio"
                :ruta-param="$organismo->nif" />
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
                <x-ui.dato etiqueta="Mayor contrato">
                    @if($contratoMayor)
                        <a href="{{ route('contratos.show', $contratoMayor->placsp_id) }}" class="block text-lg font-bold text-gray-900 hover:text-primary hover:underline">
                            {{ formatImporteCorto($contratoMayor->importe_adjudicacion) }}
                        </a>
                        <p class="mt-1 text-sm text-gray-500">
                            {{ Str::limit($contratoMayor->objeto, 70) }}
                            @if($contratoMayor->adjudicatario)
                                · {{ Str::limit($contratoMayor->adjudicatario->nombre, 40) }}
                            @endif
                        </p>
                    @else
                        <p class="text-lg font-bold text-gray-400">—</p>
                    @endif
                </x-ui.dato>
            </div>
        </x-ui.seccion>

        {{-- Acumulado histórico por organismo y tipo --}}
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
            <x-ui.seccion titulo="Adjudicatarios (histórico)">
                <x-ui.tarjeta :padding="false">
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <caption class="sr-only">Adjudicatarios con más importe de {{ $organismo->nombre }}</caption>
                            <thead class="bg-gray-50 text-gray-600 uppercase text-xs">
                                <tr>
                                    <th scope="col" class="px-4 py-3 text-left">#</th>
                                    <th scope="col" class="px-4 py-3 text-left">Nombre</th>
                                    <th scope="col" class="px-4 py-3 text-right">Contratos</th>
                                    <th scope="col" class="px-4 py-3 text-right">Importe</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach($ficha['top'] as $i => $item)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-3 text-gray-500 text-xs">{{ $i + 1 }}</td>
                                    <td class="px-4 py-3">
                                        @if($item->adjudicatario)
                                            <a href="{{ route('empresas.show', $item->adjudicatario->nif) }}" class="text-gray-900 hover:text-primary hover:underline">
                                                {{ Str::limit($item->adjudicatario->nombre, 50) }}
                                            </a>
                                        @else
                                            <span class="text-gray-500">—</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-right">{{ number_format($item->num_contratos, 0, ',', '.') }}</td>
                                    <td class="px-4 py-3 text-right font-medium whitespace-nowrap">{{ formatImporteCorto($item->total_importe) }}</td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-ui.tarjeta>
            </x-ui.seccion>

            <x-ui.seccion titulo="Tipos de contrato (histórico)">
                <x-ui.tarjeta :padding="false">
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <caption class="sr-only">Distribución histórica por tipo de contrato</caption>
                            <thead class="bg-gray-50 text-gray-600 uppercase text-xs">
                                <tr>
                                    <th scope="col" class="px-4 py-3 text-left">Tipo</th>
                                    <th scope="col" class="px-4 py-3 text-right">Contratos</th>
                                    <th scope="col" class="px-4 py-3 text-right">Importe</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach($ficha['distribucion_tipo'] as $item)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-3 text-gray-900">{{ $tipos_contrato[$item->tipo_contrato] ?? $item->tipo_contrato }}</td>
                                    <td class="px-4 py-3 text-right">{{ number_format($item->num_contratos, 0, ',', '.') }}</td>
                                    <td class="px-4 py-3 text-right font-medium whitespace-nowrap">{{ formatImporteCorto($item->total_importe) }}</td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-ui.tarjeta>
            </x-ui.seccion>
        </div>

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
