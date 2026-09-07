@php
    $periodoComparado = $comparativa['completo']
        ? 'vs '.$comparativa['anio_anterior']
        : 'en '.$comparativa['periodo'].' vs '.$comparativa['anio_anterior'];
    $comparableConElAnio = $comparativa['anio'] === $anio;
@endphp
<x-layouts.app title="Contratación Abierta — Dashboard">

    <h1 class="sr-only">Contratación pública en España: panel de indicadores</h1>

    <div class="space-y-10 sm:space-y-12">

        {{-- Bloque principal: el año en curso, no el acumulado de 18 años (que vive en
             /historico). Cifras y gráfico del año van juntos en la única pieza elevada
             de la portada. --}}
        <div>
            <x-ui.panel destacado kpi-cols="grid-cols-1 sm:grid-cols-2 lg:grid-cols-4">
                <x-slot:cabecera>
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h2 class="font-serif text-xl font-semibold tracking-tight text-gray-900 sm:text-2xl">
                            Contratación en {{ $anio }}
                            @if($anio === $anioActual)
                                <span class="font-sans text-sm font-normal text-gray-500">(año en curso)</span>
                            @endif
                        </h2>
                        <a href="{{ route('historico') }}" class="text-sm font-medium text-primary hover:underline">
                            Ver datos históricos →
                        </a>
                    </div>
                </x-slot:cabecera>

                <x-slot:kpis>
                    <x-serie.kpi-delta plano
                        etiqueta="Contratos"
                        :valor="number_format($totalAnio['num'], 0, ',', '.')"
                        :delta="$comparableConElAnio ? $comparativa['variacion_num'] : null"
                        :periodo="$comparableConElAnio ? $periodoComparado : null" />

                    <x-serie.kpi-delta plano destacado
                        etiqueta="Volumen adjudicado"
                        :valor="formatImporteCorto($totalAnio['importe'])"
                        :delta="$comparableConElAnio ? $comparativa['variacion_importe'] : null"
                        :periodo="$comparableConElAnio ? $periodoComparado : null" />

                    <x-serie.kpi-delta plano
                        etiqueta="Organismos"
                        :valor="number_format($totalesHistoricos['organismos'], 0, ',', '.')"
                        delta-abs="registrados en total" />

                    <x-serie.kpi-delta plano
                        etiqueta="Adjudicatarios"
                        :valor="number_format($totalesHistoricos['adjudicatarios'], 0, ',', '.')"
                        delta-abs="registrados en total" />
                </x-slot:kpis>

                <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                    Mes a mes: {{ implode(' vs ', array_keys($seriesGrafico)) }}
                </h3>
                <div class="mt-3">
                    <x-serie.grafico-mensual id="portadaGraficoMensual" :series="$seriesGrafico" height="320px" />
                </div>
            </x-ui.panel>

            <p class="mt-3 text-sm text-gray-500">
                Acumulado desde {{ $serie->primerAnio() }}:
                <strong>{{ number_format($serie->totales()['num'], 0, ',', '.') }}</strong> contratos por
                <strong>{{ formatImporteCorto($serie->totales()['importe']) }}</strong>.
                <a href="{{ route('historico') }}" class="text-primary hover:underline">Ver histórico completo</a>.
            </p>
            @if($comparableConElAnio && ! $comparativa['completo'])
                <x-ui.nota class="mt-1">
                    Las variaciones comparan {{ $comparativa['periodo'] }} de {{ $comparativa['anio'] }} con el mismo
                    periodo de {{ $comparativa['anio_anterior'] }}, para no medir un año incompleto contra uno entero.
                </x-ui.nota>
            @endif
        </div>

        {{-- Evolución anual: el contexto largo bajo el detalle del año en curso, a media
             altura para que no le robe protagonismo. El desglose está en /historico. --}}
        @php $aniosPortada = array_slice($serie->anios(), 0, 12); @endphp
        @if(count($aniosPortada) > 1)
        <x-ui.seccion titulo="Evolución por años">
            <x-slot:accion>
                <a href="{{ route('historico') }}" class="font-medium text-primary hover:underline">Ver todos los años →</a>
            </x-slot:accion>
            <x-ui.tarjeta>
                <x-chart id="portadaGraficoAnual" height="160px" />
            </x-ui.tarjeta>
        </x-ui.seccion>
        @endif

        {{-- Gráficas --}}
        @if($charts)
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
            <x-ui.seccion titulo="Contratación por comunidad autónoma">
                <x-ui.tarjeta>
                    <x-chart id="chartCcaa" type="bar" height="300px" />
                </x-ui.tarjeta>
            </x-ui.seccion>

            <x-ui.seccion titulo="Top 10 sectores (CPV)">
                <x-ui.tarjeta>
                    <x-chart id="chartCpv" type="bar" height="300px" />
                </x-ui.tarjeta>
            </x-ui.seccion>
        </div>
        @endif

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
            {{-- Top 10 Empresas --}}
            <x-ui.seccion :titulo="$rankingsAnio ? 'Top 10 adjudicatarios en '.$rankingsAnio['anio'] : 'Top 10 adjudicatarios por importe (histórico)'">
                <x-ui.tarjeta :padding="false">
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <caption class="sr-only">{{ $rankingsAnio ? 'Top 10 adjudicatarios en '.$rankingsAnio['anio'] : 'Top 10 adjudicatarios por importe (histórico)' }}</caption>
                            <thead class="bg-gray-50 text-gray-600 uppercase text-xs">
                                <tr>
                                    <th scope="col" class="px-3 py-3 text-left">#</th>
                                    <th scope="col" class="px-3 py-3 text-left">Nombre</th>
                                    <th scope="col" class="px-3 py-3 text-right">Contratos</th>
                                    <th scope="col" class="px-3 py-3 text-right">Importe</th>
                                    <th scope="col" class="px-3 py-3 text-left hidden sm:table-cell w-16">Tendencia</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach($rankingsAnio['top_empresas'] ?? $topEmpresas as $i => $empresa)
                                @php $fila = is_array($empresa) ? $empresa : ['nombre' => $empresa->nombre, 'nif' => $empresa->nif, 'num_contratos' => $empresa->total_contratos, 'total_importe' => $empresa->total_importe]; @endphp
                                <tr class="hover:bg-gray-50">
                                    <td class="px-3 py-3 text-gray-500">{{ $i + 1 }}</td>
                                    <td class="px-3 py-3 font-medium text-gray-900 max-w-[13rem] truncate">
                                        <a href="{{ route('empresas.show', $fila['nif']) }}" class="hover:text-primary hover:underline">{{ $fila['nombre'] }}</a>
                                    </td>
                                    <td class="px-3 py-3 text-right">{{ number_format($fila['num_contratos'], 0, ',', '.') }}</td>
                                    <td class="px-3 py-3 text-right font-medium whitespace-nowrap">{{ formatImporteCorto($fila['total_importe']) }}</td>
                                    <td class="px-3 py-3 hidden sm:table-cell">
                                        <x-serie.sparkline :valores="$fila['serie'] ?? []" :anio="$rankingsAnio['anio'] ?? null" :ancho="56" :alto="20" />
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-ui.tarjeta>
            </x-ui.seccion>

            {{-- Top 10 Organismos --}}
            <x-ui.seccion :titulo="$rankingsAnio ? 'Top 10 organismos en '.$rankingsAnio['anio'] : 'Top 10 organismos por importe (histórico)'">
                <x-ui.tarjeta :padding="false">
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <caption class="sr-only">{{ $rankingsAnio ? 'Top 10 organismos en '.$rankingsAnio['anio'] : 'Top 10 organismos por importe (histórico)' }}</caption>
                            <thead class="bg-gray-50 text-gray-600 uppercase text-xs">
                                <tr>
                                    <th scope="col" class="px-3 py-3 text-left">#</th>
                                    <th scope="col" class="px-3 py-3 text-left">Nombre</th>
                                    <th scope="col" class="px-3 py-3 text-right">Contratos</th>
                                    <th scope="col" class="px-3 py-3 text-right">Importe</th>
                                    <th scope="col" class="px-3 py-3 text-left hidden sm:table-cell w-16">Tendencia</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach($rankingsAnio['top_organismos'] ?? $topOrganismos as $i => $org)
                                @php $fila = is_array($org) ? $org : ['nombre' => $org->nombre, 'nif' => $org->nif, 'num_contratos' => $org->total_contratos, 'total_importe' => $org->total_importe]; @endphp
                                <tr class="hover:bg-gray-50">
                                    <td class="px-3 py-3 text-gray-500">{{ $i + 1 }}</td>
                                    <td class="px-3 py-3 font-medium text-gray-900 max-w-[13rem] truncate">
                                        <a href="{{ route('organismos.show', $fila['nif']) }}" class="hover:text-primary hover:underline">{{ $fila['nombre'] }}</a>
                                    </td>
                                    <td class="px-3 py-3 text-right">{{ number_format($fila['num_contratos'], 0, ',', '.') }}</td>
                                    <td class="px-3 py-3 text-right font-medium whitespace-nowrap">{{ formatImporteCorto($fila['total_importe']) }}</td>
                                    <td class="px-3 py-3 hidden sm:table-cell">
                                        <x-serie.sparkline :valores="$fila['serie'] ?? []" :anio="$rankingsAnio['anio'] ?? null" :ancho="56" :alto="20" />
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-ui.tarjeta>
            </x-ui.seccion>
        </div>

        {{-- Últimos 20 contratos --}}
        <x-ui.seccion titulo="Últimos 20 contratos publicados">
            <x-ui.tarjeta :padding="false">
                <x-tabla.contratos
                    :contratos="$ultimosContratos"
                    caption="Últimos 20 contratos publicados"
                    contraparte="organismo"
                    contraparte-etiqueta="Organismo"
                    :contraparte-limite="0"
                    :contraparte-solo-md="false"
                    :importe-corto="false"
                    :mostrar-placsp="true"
                />
            </x-ui.tarjeta>
        </x-ui.seccion>
    </div>


<x-serie.chartjs />

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (typeof Chart === 'undefined') return;

    const anualData = @json(array_reverse(array_slice($serie->anios(), 0, 12)));
    const canvasAnual = document.getElementById('portadaGraficoAnual');
    if (!canvasAnual || anualData.length < 2) return;

    const anioActual = @json($anio);

    new Chart(canvasAnual, {
        type: 'bar',
        data: {
            labels: anualData.map(d => d.anio),
            datasets: [{
                label: 'Importe adjudicado',
                data: anualData.map(d => d.importe),
                // El año en curso destaca; los cerrados quedan como contexto.
                backgroundColor: anualData.map(d => d.anio === anioActual ? '#dc2626' : 'rgba(148,163,184,0.55)'),
                borderRadius: 3,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: ctx => {
                            const fila = anualData[ctx.dataIndex];
                            return window.formatImporteCorto(fila.importe)
                                + ' · ' + fila.num.toLocaleString('es-ES') + ' contratos'
                                + (fila.anio === anioActual ? ' (año en curso)' : '');
                        }
                    }
                }
            },
            scales: {
                x: { grid: { display: false } },
                y: { ticks: { callback: v => window.formatImporteCorto(v), maxTicksLimit: 4 } }
            }
        }
    });
});
</script>
@endpush

@if($charts)
@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    if (typeof Chart === 'undefined') return;
    const chartColors = ['#dc2626','#2563eb','#16a34a','#d97706','#7c3aed','#db2777','#0891b2','#65a30d','#ea580c','#6366f1'];
    const fmt = window.formatImporteCorto;

    // Contratación por comunidad autónoma (horizontal bar, top 10)
    const ccaaData = @json($charts['distribucion_ccaa'] ?? []);
    const ccaaTop = ccaaData.slice(0, 10);
    new Chart(document.getElementById('chartCcaa'), {
        type: 'bar',
        data: {
            labels: ccaaTop.map(d => d.nombre),
            datasets: [{
                label: 'Importe adjudicado',
                data: ccaaTop.map(d => d.total_importe),
                backgroundColor: 'rgba(220,38,38,0.7)'
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: { callbacks: { label: ctx => fmt(ctx.parsed.x) + ' (' + ccaaTop[ctx.dataIndex].total_contratos.toLocaleString('es-ES') + ' contratos)' } }
            },
            scales: {
                x: { ticks: { callback: v => fmt(v) } }
            }
        }
    });

    // Top CPV (horizontal bar)
    const cpvData = @json($charts['top_cpv']);
    new Chart(document.getElementById('chartCpv'), {
        type: 'bar',
        data: {
            labels: cpvData.map(d => d.descripcion),
            datasets: [{
                label: 'Importe total',
                data: cpvData.map(d => d.total_importe),
                backgroundColor: chartColors.slice(0, cpvData.length)
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: { callbacks: { label: ctx => fmt(ctx.parsed.x) } }
            },
            scales: {
                x: { ticks: { callback: v => fmt(v) } }
            }
        }
    });
});
</script>
@endpush
@endif

</x-layouts.app>
