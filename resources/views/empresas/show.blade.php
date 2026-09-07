@php
    $kpis = $ficha['kpis'];
    $totales = $serie->totales();
    $inactiva = $serie->inactivaEn($anioActual);
    $urlHistorico = route('empresas.historico', $empresa->nif);

    // Si el año de la URL es el mismo que la ficha muestra por defecto, ambas páginas
    // tienen el mismo contenido: la canónica es la ficha, no la variante con año.
    $canonical = ($anioFijado && $anio === $serie->anioReferencia($anioActual))
        ? route('empresas.show', $empresa->nif)
        : url()->current();

    $metaDesc = $empresa->nombre.' (NIF '.$empresa->nif.'): '
        .number_format($kpis['contratos'], 0, ',', '.').' contratos públicos en '.$anio
        .(! empty($kpis['importe']) ? ' por '.formatImporteCorto($kpis['importe']) : '')
        .'. Organismos que le adjudican, evolución mensual e histórico desde '.($serie->primerAnio() ?? $anio).'.';

    // Comparativa mes a mes del año mostrado contra el anterior.
    $seriesGrafico = $serie->mesesDe(array_values(array_filter([$anio, $anio - 1], fn ($a) => $serie->tieneDatosEn($a))), $anioActual);
@endphp
<x-layouts.app :title="$empresa->nombre . ' — Contratación Abierta'" :metaDescription="$metaDesc" :canonical="$canonical">

    <x-seo.breadcrumb :items="[
        ['name' => 'Adjudicatarios', 'url' => route('empresas.index')],
        ['name' => $empresa->nombre, 'url' => route('empresas.show', $empresa->nif)],
        ...($anioFijado ? [['name' => (string) $anio, 'url' => route('empresas.anio', [$empresa->nif, $anio])]] : []),
    ]" />

    {{-- Breadcrumb --}}
    <nav class="text-sm text-gray-500 mb-4">
        <a href="{{ route('empresas.index') }}" class="hover:text-primary">Empresas</a>
        <span class="mx-1">/</span>
        @if($anioFijado)
            <a href="{{ route('empresas.show', $empresa->nif) }}" class="hover:text-primary">{{ $empresa->nif }}</a>
            <span class="mx-1">/</span>
            <span class="text-gray-700">{{ $anio }}</span>
        @else
            <span class="text-gray-700">{{ $empresa->nif }}</span>
        @endif
    </nav>

    <x-ui.cabecera :titulo="$empresa->nombre">
        <x-slot:meta>
            <span class="font-mono">{{ $empresa->nif }}</span>
            @if($empresa->es_pyme)
                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-blue-100 text-blue-700">PYME</span>
            @endif
            @if(! $inactiva && $serie->tieneDatosEn($anioActual))
                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold bg-green-100 text-green-700">
                    <span class="w-1.5 h-1.5 rounded-full bg-green-600"></span>
                    Activa en {{ $anioActual }}
                </span>
            @endif
        </x-slot:meta>

        <x-slot:acciones>
            <div class="flex flex-col gap-2 md:items-end">
                @if(Route::has('empresas.informe.pdf'))
                    <a href="{{ route('empresas.informe.pdf', $empresa->nif) }}"
                       class="inline-flex items-center gap-1.5 self-start rounded-lg px-3 py-1.5 text-xs font-semibold text-primary ring-1 ring-primary/30 transition hover:bg-red-50 md:self-end">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                        Descargar informe PDF
                    </a>
                @endif

                {{-- Seguir empresa --}}
                <form action="{{ route('alertas.suscribir') }}" method="POST" class="flex flex-wrap items-center gap-2">
                    @csrf
                    <input type="hidden" name="tipo" value="adjudicatario">
                    <input type="hidden" name="filtro_valor" value="{{ $empresa->nif }}">
                    <input type="hidden" name="filtro_nombre" value="{{ $empresa->nombre }}">
                    <label for="seguir-email" class="sr-only">Correo para recibir alertas de esta empresa</label>
                    <input id="seguir-email" type="email" name="email" placeholder="tu@email.com" required
                           class="rounded-lg border border-gray-300 text-sm px-3 py-1.5 w-48 focus:ring-primary focus:border-primary @error('email') border-red-500 @enderror">
                    <button type="submit" class="inline-flex items-center px-3 py-1.5 bg-primary text-white rounded-lg hover:bg-primary-dark transition text-sm font-medium">
                        Seguir
                    </button>
                    @error('email')
                        <span class="text-red-500 text-xs w-full md:text-right">{{ $message }}</span>
                    @enderror
                </form>
            </div>
        </x-slot:acciones>
    </x-ui.cabecera>

    {{-- Aliases --}}
    <x-entidad.aliases :aliases="$aliases" />

    <x-entidad.pestanas
        activa="ficha"
        :url-ficha="route('empresas.show', $empresa->nif)"
        :url-historico="route('empresas.historico', $empresa->nif)"
        :url-red="route('empresas.red', $empresa->nif)"
        etiqueta-ficha="Ficha {{ $anio }}" />

    @if($serie->estaVacia())
        {{-- Sin ningún contrato fechado: no hay ficha anual que mostrar. --}}
        <x-ui.estado-vacio titulo="Sin contratos con fecha de publicación">
            No hay datos temporales para esta empresa.
            @if($serie->sinFecha > 0)
                Constan {{ number_format($serie->sinFecha, 0, ',', '.') }} contratos sin fecha de publicación.
            @endif
        </x-ui.estado-vacio>
    @else

    {{-- Ritmo vertical de la página: una única separación entre bloques, para no depender
         de márgenes distintos en cada sección. --}}
    <div class="space-y-10 sm:space-y-12">

        @if($inactiva && ! $anioFijado)
            <x-serie.aviso-inactiva
                :anio-actual="$anioActual"
                :anio-referencia="$anio"
                :url-historico="$urlHistorico"
                entidad="Esta empresa" />
        @endif

        @php
            // El valor del KPI es el del año entero hasta hoy; la variación, sólo del tramo
            // cerrado. La etiqueta lo dice para que no parezca que ambos miden lo mismo.
            $periodoComparado = $comparativa['completo']
                ? 'vs '.$comparativa['anio_anterior']
                : 'en '.$comparativa['periodo'].' vs '.$comparativa['anio_anterior'];
            $comparableConElAnio = $comparativa['anio'] === $anio;
        @endphp

        {{-- Bloque principal: el año en curso resuelto de un vistazo (cifras + gráfico).
             Es lo único elevado de la página; el resto queda por debajo. --}}
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
                            ruta-ficha="empresas.show"
                            ruta-anio="empresas.anio"
                            :param="$empresa->nif"
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
                        :delta-abs="'distintos en '.$anio" />

                    <x-serie.kpi-delta plano
                        etiqueta="Contrato mayor"
                        :valor="formatImporteCorto($kpis['contrato_mayor'])"
                        :delta-abs="$kpis['ticket_medio'] !== null ? 'media: '.formatImporteCorto($kpis['ticket_medio']) : null" />
                </x-slot:kpis>

                <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                    Mes a mes: {{ implode(' vs ', array_keys($seriesGrafico)) }}
                </h3>
                <div class="mt-3">
                    <x-serie.grafico-mensual id="empGraficoMensual" :series="$seriesGrafico" />
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

        @if($kpis['contratos'] === 0)
            <x-ui.estado-vacio titulo="Sin contratos en {{ $anio }}">
                Prueba con otro año o consulta el
                <a href="{{ $urlHistorico }}" class="text-primary hover:underline font-medium">histórico completo</a>.
            </x-ui.estado-vacio>
        @else

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
            {{-- Top 10 organismos del año --}}
            <x-ui.seccion :titulo="'Principales organismos contratantes en '.$anio">
                <x-ui.tarjeta :padding="false">
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <caption class="sr-only">Organismos que más adjudicaron a {{ $empresa->nombre }} en {{ $anio }}</caption>
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
                                        @if($item->organismo)
                                            <a href="{{ route('organismos.show', $item->organismo->nif) }}" class="text-gray-900 hover:text-primary hover:underline">
                                                {{ Str::limit($item->organismo->nombre, 50) }}
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
                        <x-chart id="empChartTipo" type="doughnut" height="250px" />
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
                    :contratos="$contratos"
                    :caption="'Contratos de '.$empresa->nombre.' en '.$anio"
                    contraparte="organismo"
                    contraparte-etiqueta="Organismo"
                    contraparte-ruta="organismos.show"
                    :mostrar-menor="true"
                />
            </x-ui.tarjeta>

            @if($contratos->hasPages())
                <div class="mt-6">
                    {{ $contratos->links() }}
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
    const canvasTipo = document.getElementById('empChartTipo');
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
