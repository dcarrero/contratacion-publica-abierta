@php
    $kpis = $ficha['kpis'];
    $inactivo = $serie->inactivaEn($anioActual);
    $urlHistorico = route('organismos.historico', $organismo->nif);

    // Si el año de la URL es el mismo que la ficha muestra por defecto, ambas páginas
    // tienen el mismo contenido: la canónica es la ficha, no la variante con año.
    $canonical = ($anioFijado && $anio === $serie->anioReferencia($anioActual))
        ? route('organismos.show', $organismo->nif)
        : url()->current();

    $metaDesc = $organismo->nombre.' (NIF '.$organismo->nif.'): '
        .number_format($kpis['contratos'], 0, ',', '.').' contratos públicos en '.$anio
        .(! empty($kpis['importe']) ? ' por '.formatImporteCorto($kpis['importe']) : '')
        .'. Principales proveedores, concurrencia y evolución del gasto.';

    $seriesGrafico = $serie->mesesDe(array_values(array_filter([$anio, $anio - 1], fn ($a) => $serie->tieneDatosEn($a))), $anioActual);
@endphp
<x-layouts.app :title="$organismo->nombre . ' — Contratación Abierta'" :metaDescription="$metaDesc" :canonical="$canonical">

    <x-seo.breadcrumb :items="[
        ['name' => 'Organismos', 'url' => route('organismos.index')],
        ['name' => $organismo->nombre, 'url' => route('organismos.show', $organismo->nif)],
        ...($anioFijado ? [['name' => (string) $anio, 'url' => route('organismos.anio', [$organismo->nif, $anio])]] : []),
    ]" />

    {{-- Breadcrumb --}}
    <nav class="text-sm text-gray-500 mb-4">
        <a href="{{ route('organismos.index') }}" class="hover:text-primary">Organismos</a>
        <span class="mx-1">/</span>
        @if($anioFijado)
            <a href="{{ route('organismos.show', $organismo->nif) }}" class="hover:text-primary">{{ $organismo->nif }}</a>
            <span class="mx-1">/</span>
            <span class="text-gray-700">{{ $anio }}</span>
        @else
            <span class="text-gray-700">{{ $organismo->nif }}</span>
        @endif
    </nav>

    <x-ui.cabecera :titulo="$organismo->nombre">
        <x-slot:meta>
            <span class="font-mono">{{ $organismo->nif }}</span>
            @if($organismo->tipo)
                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-600">{{ ucfirst($organismo->tipo) }}</span>
            @endif
            @if(! $inactivo && $serie->tieneDatosEn($anioActual))
                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold bg-green-100 text-green-700">
                    <span class="w-1.5 h-1.5 rounded-full bg-green-600"></span>
                    Activo en {{ $anioActual }}
                </span>
            @endif
            @if($organismo->dir3)
                <span class="text-xs">DIR3: {{ $organismo->dir3 }}</span>
            @endif
            @if($organismo->url_perfil_placsp)
                <a href="{{ $organismo->url_perfil_placsp }}" target="_blank" rel="noopener" class="text-primary hover:underline text-xs">Ver en PLACSP</a>
            @endif
        </x-slot:meta>

        <x-slot:acciones>
            {{-- Seguir organismo --}}
            <form action="{{ route('alertas.suscribir') }}" method="POST" class="flex flex-wrap items-center gap-2 md:justify-end">
                @csrf
                <input type="hidden" name="tipo" value="organismo">
                <input type="hidden" name="filtro_valor" value="{{ $organismo->nif }}">
                <input type="hidden" name="filtro_nombre" value="{{ $organismo->nombre }}">
                <label for="seguir-email" class="sr-only">Correo para recibir alertas de este organismo</label>
                <input id="seguir-email" type="email" name="email" placeholder="tu@email.com" required
                       class="rounded-lg border border-gray-300 text-sm px-3 py-1.5 w-48 focus:ring-primary focus:border-primary @error('email') border-red-500 @enderror">
                <button type="submit" class="inline-flex items-center px-3 py-1.5 bg-primary text-white rounded-lg hover:bg-primary-dark transition text-sm font-medium">
                    Seguir
                </button>
                @error('email')
                    <span class="text-red-500 text-xs w-full md:text-right">{{ $message }}</span>
                @enderror
            </form>
        </x-slot:acciones>
    </x-ui.cabecera>

    <x-entidad.pestanas
        activa="ficha"
        :url-ficha="route('organismos.show', $organismo->nif)"
        :url-historico="route('organismos.historico', $organismo->nif)"
        :url-red="route('organismos.red', $organismo->nif)"
        etiqueta-ficha="Ficha {{ $anio }}" />

    @if($serie->estaVacia())
        <x-ui.estado-vacio titulo="Sin contratos con fecha de publicación">
            No hay datos temporales para este organismo.
            @if($serie->sinFecha > 0)
                Constan {{ number_format($serie->sinFecha, 0, ',', '.') }} contratos sin fecha de publicación.
            @endif
        </x-ui.estado-vacio>
    @else

    <div class="space-y-10 sm:space-y-12">

        @if($inactivo && ! $anioFijado)
            <x-serie.aviso-inactiva
                :anio-actual="$anioActual"
                :anio-referencia="$anio"
                :url-historico="$urlHistorico"
                entidad="Este organismo" />
        @endif

        @php
            $periodoComparado = $comparativa['completo']
                ? 'vs '.$comparativa['anio_anterior']
                : 'en '.$comparativa['periodo'].' vs '.$comparativa['anio_anterior'];
            $comparableConElAnio = $comparativa['anio'] === $anio;
        @endphp

        {{-- Bloque principal: el año en curso resuelto de un vistazo (cifras + gráfico). --}}
        <div>
            <x-ui.panel destacado>
                <x-slot:cabecera>
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h2 class="font-serif text-xl font-semibold tracking-tight text-gray-900 sm:text-2xl">
                            Actividad en {{ $anio }}
                            @if($anio === $anioActual)
                                <span class="font-sans text-sm font-normal text-gray-500">(año en curso)</span>
                            @endif
                        </h2>
                        <x-serie.selector-anios
                            :anios="array_slice(array_reverse($serie->aniosConDatos()), 0, 5)"
                            :anio="$anio"
                            :anio-referencia="$serie->anioReferencia($anioActual)"
                            ruta-ficha="organismos.show"
                            ruta-anio="organismos.anio"
                            :param="$organismo->nif"
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
                        etiqueta="Adjudicatarios"
                        :valor="number_format($kpis['contrapartes'], 0, ',', '.')"
                        :delta-abs="'distintos en '.$anio" />

                    <x-serie.kpi-delta plano
                        etiqueta="% contratos menores"
                        :valor="$kpis['pct_menores'] . '%'"
                        :delta-abs="$kpis['ticket_medio'] !== null ? 'media: '.formatImporteCorto($kpis['ticket_medio']) : null" />
                </x-slot:kpis>

                <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                    Mes a mes: {{ implode(' vs ', array_keys($seriesGrafico)) }}
                </h3>
                <div class="mt-3">
                    <x-serie.grafico-mensual id="orgGraficoMensual" :series="$seriesGrafico" />
                </div>
            </x-ui.panel>

            @if($comparableConElAnio && ! $comparativa['completo'])
                <x-ui.nota class="mt-3">
                    La comparación cubre {{ $comparativa['periodo'] }} de {{ $comparativa['anio'] }} frente al mismo periodo
                    de {{ $comparativa['anio_anterior'] }}, para no comparar un año incompleto con uno entero.
                </x-ui.nota>
            @endif
        </div>

        {{-- Análisis: concurrencia y concentración de proveedores --}}
        @if($kpis['pct_sin_concurrencia'] !== null || $kpis['concentracion_hhi'] !== null)
        <x-ui.seccion :titulo="'Concurrencia y concentración en '.$anio">
            {{-- Sólo hay dos columnas si hay dos datos: con uno solo (organismos sin HHI
                 calculable) la rejilla dejaba media fila vacía a la derecha. --}}
            <div @class([
                'grid grid-cols-1 gap-4',
                'sm:grid-cols-2' => $kpis['pct_sin_concurrencia'] !== null && $kpis['concentracion_hhi'] !== null,
            ])>
                @if($kpis['pct_sin_concurrencia'] !== null)
                <x-ui.dato :etiqueta="'% adjudicaciones sin concurrencia en '.$anio">
                    <p class="text-2xl font-bold tracking-tight text-gray-900">{{ $kpis['pct_sin_concurrencia'] }}%</p>
                    <p class="mt-1 text-xs text-gray-500">
                        Una sola oferta, sobre {{ number_format($kpis['contratos_con_num_ofertas'], 0, ',', '.') }}
                        contratos que declaran número de ofertas.
                    </p>
                </x-ui.dato>
                @endif
                @if($kpis['concentracion_hhi'] !== null)
                <x-ui.dato :etiqueta="'Concentración de proveedores en '.$anio">
                    <p class="text-2xl font-bold tracking-tight text-gray-900">
                        {{ $kpis['concentracion_label'] }}
                        <span class="text-base font-normal text-gray-500">(HHI {{ number_format($kpis['concentracion_hhi'], 0, ',', '.') }})</span>
                    </p>
                    <p class="mt-1 text-xs text-gray-500">
                        Índice Herfindahl por importe (0–10.000). Más alto = mayor dependencia de pocos adjudicatarios.
                    </p>
                </x-ui.dato>
                @endif
            </div>
        </x-ui.seccion>
        @endif

        {{-- Resumen del histórico --}}
        <x-serie.tira-historico :serie="$serie" :url-historico="$urlHistorico" />

        @if($kpis['contratos'] === 0)
            <x-ui.estado-vacio titulo="Sin contratos en {{ $anio }}">
                Prueba con otro año o consulta el
                <a href="{{ $urlHistorico }}" class="text-primary hover:underline font-medium">histórico completo</a>.
            </x-ui.estado-vacio>
        @else

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
            {{-- Top 10 adjudicatarios del año --}}
            <x-ui.seccion :titulo="'Principales adjudicatarios en '.$anio">
                <x-ui.tarjeta :padding="false">
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <caption class="sr-only">Adjudicatarios con más importe de {{ $organismo->nombre }} en {{ $anio }}</caption>
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

            {{-- Distribución por tipo --}}
            <x-ui.seccion :titulo="'Tipos de contrato en '.$anio">
                <x-ui.tarjeta :padding="false">
                    @if($ficha['distribucion_tipo']->count() > 1)
                    <div class="p-5 sm:p-6">
                        <x-chart id="orgChartTipo" type="doughnut" height="250px" />
                    </div>
                    @endif
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <caption class="sr-only">Distribución por tipo de contrato en {{ $anio }}</caption>
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

        {{-- Contratos del año --}}
        <x-ui.seccion :titulo="'Contratos de '.$anio">
            <x-ui.tarjeta :padding="false">
                <x-tabla.contratos
                    :contratos="$ultimosContratos"
                    :caption="'Contratos de '.$organismo->nombre.' en '.$anio"
                    contraparte="adjudicatario"
                    contraparte-etiqueta="Adjudicatario"
                    contraparte-ruta="empresas.show"
                    :mostrar-menor="true"
                />
            </x-ui.tarjeta>

            @if($ultimosContratos->hasPages())
                <div class="mt-6">
                    {{ $ultimosContratos->links() }}
                </div>
            @endif
        </x-ui.seccion>

        @endif
    </div>
    @endif

<x-serie.chartjs />

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    if (typeof Chart === 'undefined') return;
    const chartColors = ['#dc2626','#2563eb','#16a34a','#d97706','#7c3aed','#db2777','#0891b2','#65a30d','#ea580c','#6366f1'];
    const tipos = @json($tipos_contrato);

    @if($ficha['distribucion_tipo']->count() > 1)
    const canvasTipo = document.getElementById('orgChartTipo');
    if (!canvasTipo) return;
    const tipoData = @json($ficha['distribucion_tipo']->values());
    new Chart(canvasTipo, {
        type: 'doughnut',
        data: {
            labels: tipoData.map(d => tipos[d.tipo_contrato] || d.tipo_contrato || 'Sin especificar'),
            datasets: [{
                data: tipoData.map(d => d.num_contratos),
                backgroundColor: chartColors.slice(0, tipoData.length)
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'right', labels: { boxWidth: 12, font: { size: 11 } } },
                tooltip: { callbacks: { label: ctx => ctx.label + ': ' + ctx.parsed.toLocaleString('es-ES') + ' contratos' } }
            }
        }
    });
    @endif
});
</script>
@endpush

</x-layouts.app>
