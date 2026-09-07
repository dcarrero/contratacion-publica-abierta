@php
    $kpis = $ficha['kpis'];
    $inactiva = $serie->inactivaEn($anioActual);
    $urlHistorico = route('administraciones.historico', $comunidad->nuts);

    // Si el año de la URL es el mismo que la ficha muestra por defecto, ambas páginas
    // tienen el mismo contenido: la canónica es la ficha, no la variante con año.
    $canonical = ($anioFijado && $anio === $serie->anioReferencia($anioActual))
        ? route('administraciones.show', $comunidad->nuts)
        : url()->current();
    $totales = $serie->totales();

    $metaDesc = 'Contratación pública de '.$comunidad->nombre.' en '.$anio.': '
        .number_format($kpis['contratos'], 0, ',', '.').' contratos'
        .(! empty($kpis['importe']) ? ' por '.formatImporteCorto($kpis['importe']) : '')
        .'. Evolución mensual, principales organismos e histórico desde '.($serie->primerAnio() ?? $anio).'.';

    $seriesGrafico = $serie->mesesDe(array_values(array_filter([$anio, $anio - 1], fn ($a) => $serie->tieneDatosEn($a))), $anioActual);

    $periodoComparado = $comparativa['completo']
        ? 'vs '.$comparativa['anio_anterior']
        : 'en '.$comparativa['periodo'].' vs '.$comparativa['anio_anterior'];
    $comparableConElAnio = $comparativa['anio'] === $anio;
@endphp
<x-layouts.app :title="$comunidad->nombre . ' — Contratación Abierta'" :metaDescription="$metaDesc" :canonical="$canonical">

    <x-seo.breadcrumb :items="[
        ['name' => 'Administraciones', 'url' => route('administraciones.index')],
        ['name' => $comunidad->nombre, 'url' => route('administraciones.show', $comunidad->nuts)],
        ...($anioFijado ? [['name' => (string) $anio, 'url' => route('administraciones.anio', [$comunidad->nuts, $anio])]] : []),
    ]" />

    {{-- Breadcrumb --}}
    <nav class="text-sm text-gray-500 mb-4">
        <a href="{{ route('administraciones.index') }}" class="hover:text-primary">Administraciones</a>
        <span class="mx-1">/</span>
        @if($anioFijado)
            <a href="{{ route('administraciones.show', $comunidad->nuts) }}" class="hover:text-primary">{{ $comunidad->nombre }}</a>
            <span class="mx-1">/</span>
            <span class="text-gray-700">{{ $anio }}</span>
        @else
            <span class="text-gray-700">{{ $comunidad->nombre }}</span>
        @endif
    </nav>

    <x-ui.cabecera :titulo="$comunidad->nombre">
        <x-slot:meta>
            <span>Código NUTS: {{ $comunidad->nuts }}</span>
        </x-slot:meta>
    </x-ui.cabecera>

    @if($serie->estaVacia())
        <x-ui.estado-vacio titulo="Sin contratos con fecha de publicación">
            No hay datos temporales para esta comunidad.
        </x-ui.estado-vacio>
    @else

    <div class="space-y-10 sm:space-y-12">

        @if($inactiva && ! $anioFijado)
            <x-serie.aviso-inactiva
                :anio-actual="$anioActual"
                :anio-referencia="$anio"
                :url-historico="$urlHistorico"
                entidad="Esta comunidad" />
        @endif

        {{-- Bloque principal: el año en curso resuelto de un vistazo (cifras + gráfico). --}}
        <div>
            <x-ui.panel destacado>
                <x-slot:cabecera>
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h2 class="font-serif text-xl font-semibold tracking-tight text-gray-900 sm:text-2xl">
                            Contratación en {{ $anio }}
                            @if($anio === $anioActual)
                                <span class="font-sans text-sm font-normal text-gray-500">(año en curso)</span>
                            @endif
                        </h2>
                        <x-serie.selector-anios
                            :anios="array_slice(array_reverse($serie->aniosConDatos()), 0, 5)"
                            :anio="$anio"
                            :anio-referencia="$serie->anioReferencia($anioActual)"
                            ruta-ficha="administraciones.show"
                            ruta-anio="administraciones.anio"
                            :param="$comunidad->nuts"
                            :url-historico="$urlHistorico" />
                    </div>
                </x-slot:cabecera>

                <x-slot:kpis>
                    <x-serie.kpi-delta plano
                        etiqueta="Contratos"
                        :valor="number_format($kpis['contratos'], 0, ',', '.')"
                        :delta="$comparableConElAnio ? $comparativa['variacion_num'] : null"
                        :periodo="$comparableConElAnio ? $periodoComparado : null" />

                    <x-serie.kpi-delta plano destacado
                        etiqueta="Importe adjudicado"
                        :valor="formatImporteCorto($kpis['importe'])"
                        :delta="$comparableConElAnio ? $comparativa['variacion_importe'] : null"
                        :periodo="$comparableConElAnio ? $periodoComparado : null" />

                    <x-serie.kpi-delta plano
                        etiqueta="Organismos"
                        :valor="number_format($kpis['contrapartes'], 0, ',', '.')"
                        :delta-abs="'con contratos en '.$anio" />

                    <x-serie.kpi-delta plano
                        etiqueta="Gasto por habitante"
                        :valor="$comunidad->poblacion > 0 ? formatImporte($kpis['importe'] / $comunidad->poblacion).'/hab' : '—'"
                        :delta-abs="$comunidad->poblacion > 0 ? number_format($comunidad->poblacion, 0, ',', '.').' habitantes (INE)' : null" />
                </x-slot:kpis>

                <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                    Mes a mes: {{ implode(' vs ', array_keys($seriesGrafico)) }}
                </h3>
                <div class="mt-3">
                    <x-serie.grafico-mensual id="adminGraficoMensual" :series="$seriesGrafico" />
                </div>
            </x-ui.panel>

            @if($comparableConElAnio && ! $comparativa['completo'])
                <x-ui.nota class="mt-3">
                    La comparación cubre {{ $comparativa['periodo'] }} de {{ $comparativa['anio'] }} frente al mismo periodo
                    de {{ $comparativa['anio_anterior'] }}, para no comparar un año incompleto con uno entero.
                </x-ui.nota>
            @endif
        </div>

        {{-- Resumen del histórico --}}
        <x-serie.tira-historico :serie="$serie" :url-historico="$urlHistorico" />

        {{-- Totales estructurales de la comunidad: contexto, no indicadores del año. Van sin
             caja propia, como una línea de datos entre filetes. --}}
        <div>
            <dl class="grid grid-cols-2 gap-x-8 gap-y-4 border-y border-gray-200 py-4 sm:grid-cols-3" data-cifras>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-gray-500">Organismos registrados</dt>
                    <dd class="mt-0.5 text-lg font-bold text-gray-900">
                        {{ $total_organismos !== null ? number_format($total_organismos, 0, ',', '.') : '—' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-gray-500">Adjudicatarios distintos</dt>
                    <dd class="mt-0.5 text-lg font-bold text-gray-900">
                        {{ $total_adjudicatarios !== null ? number_format($total_adjudicatarios, 0, ',', '.') : '—' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-gray-500">Contratos históricos</dt>
                    <dd class="mt-0.5 text-lg font-bold text-gray-900">{{ number_format($totales['num'], 0, ',', '.') }}</dd>
                </div>
            </dl>

            @unless($stats_precomputadas)
                <x-ui.nota class="mt-2">
                    Los recuentos de organismos y adjudicatarios se actualizan en el recálculo periódico de
                    estadísticas y todavía no están disponibles para esta comunidad.
                </x-ui.nota>
            @endunless
        </div>

        <div class="grid lg:grid-cols-2 gap-8">
            {{-- Top organismos del año --}}
            <x-ui.seccion :titulo="'Organismos que más contrataron en '.$anio">
                <x-slot:accion>
                    <a href="{{ route('organismos.index', ['ccaa' => $comunidad->nuts]) }}" class="font-medium text-primary hover:underline">
                        Ver todos los organismos →
                    </a>
                </x-slot:accion>
                <x-ui.tarjeta :padding="false">
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <caption class="sr-only">Principales organismos de {{ $comunidad->nombre }} en {{ $anio }}</caption>
                            <thead class="bg-gray-50 text-gray-600 uppercase text-xs">
                                <tr>
                                    <th scope="col" class="px-4 py-2 text-left">Nombre</th>
                                    <th scope="col" class="px-4 py-2 text-right">Contratos</th>
                                    <th scope="col" class="px-4 py-2 text-right">Importe</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @forelse($ficha['top'] as $item)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-2">
                                        @if($item->organismo)
                                            <a href="{{ route('organismos.show', $item->organismo->nif) }}" class="text-gray-900 hover:text-primary hover:underline">
                                                {{ Str::limit($item->organismo->nombre, 50) }}
                                            </a>
                                        @else
                                            <span class="text-gray-500">—</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2 text-right text-gray-500">{{ number_format($item->num_contratos, 0, ',', '.') }}</td>
                                    <td class="px-4 py-2 text-right font-medium whitespace-nowrap">{{ formatImporteCorto($item->total_importe) }}</td>
                                </tr>
                                @empty
                                <tr><td colspan="3" class="px-4 py-6 text-center text-gray-500">Sin contratos en {{ $anio }}.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </x-ui.tarjeta>
            </x-ui.seccion>

            {{-- Últimos contratos del año --}}
            <x-ui.seccion :titulo="'Últimos contratos de '.$anio">
                <x-slot:accion>
                    <a href="{{ route('contratos.index', ['ccaa' => $comunidad->nuts]) }}" class="font-medium text-primary hover:underline">
                        Ver todos los contratos →
                    </a>
                </x-slot:accion>
                <x-ui.tarjeta :padding="false">
                    <x-tabla.contratos
                        :contratos="$ultimos_contratos"
                        :caption="'Últimos contratos de '.$comunidad->nombre.' en '.$anio"
                        :objeto-limite="60"
                        :vacio="'Sin contratos en '.$anio.'.'"
                    />
                </x-ui.tarjeta>
            </x-ui.seccion>
        </div>
    </div>

    @endif

</x-layouts.app>
