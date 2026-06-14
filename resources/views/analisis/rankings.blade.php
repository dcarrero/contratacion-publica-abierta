<x-layouts.app title="Rankings — Contratación Abierta">

    <h1 class="text-2xl font-bold text-gray-900 mb-2">Análisis de la contratación pública</h1>

    {{-- Sub-navegación --}}
    @include('analisis._subnav', ['active' => 'rankings'])

    @if(!$rankings)
    <div class="bg-amber-50 border border-amber-200 rounded-lg p-6 text-amber-800">
        <p class="font-medium">No hay datos de rankings disponibles.</p>
        <p class="text-sm mt-1">Ejecuta <code class="bg-amber-100 px-1 rounded">php artisan stats:recalculate --entity=rankings</code> para generar los datos.</p>
    </div>
    @else

    {{-- Sección 1: Top organismos por % contratos menores --}}
    <section class="mb-10">
        <h2 class="text-lg font-semibold text-gray-900 mb-2">Organismos con mayor porcentaje de contratos menores</h2>
        <p class="text-sm text-gray-500 mb-4">Top 50 organismos (mín. 20 contratos). Un ratio alto de contratos menores puede indicar menor competencia.</p>
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-gray-600 uppercase text-xs">
                        <tr>
                            <th class="px-4 py-3 text-left">#</th>
                            <th class="px-4 py-3 text-left">Organismo</th>
                            <th class="px-4 py-3 text-right">Contratos</th>
                            <th class="px-4 py-3 text-right">Menores</th>
                            <th class="px-4 py-3 text-right">% Menores</th>
                            <th class="px-4 py-3 text-right">Importe total</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($rankings['top_organismos_pct_menores'] as $i => $org)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 text-gray-400">{{ $i + 1 }}</td>
                            <td class="px-4 py-3 font-medium text-gray-900">
                                <a href="{{ route('organismos.show', $org['nif']) }}" class="hover:text-primary hover:underline">{{ Str::limit($org['nombre'], 55) }}</a>
                            </td>
                            <td class="px-4 py-3 text-right">{{ number_format($org['total_contratos'], 0, ',', '.') }}</td>
                            <td class="px-4 py-3 text-right">{{ number_format($org['total_menores'], 0, ',', '.') }}</td>
                            <td class="px-4 py-3 text-right">
                                @php
                                    $badgeColor = $org['pct_menores'] >= 80
                                        ? 'bg-red-100 text-red-700'
                                        : ($org['pct_menores'] >= 60 ? 'bg-amber-100 text-amber-700' : 'bg-gray-100 text-gray-600');
                                @endphp
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium {{ $badgeColor }}">
                                    {{ number_format($org['pct_menores'], 1, ',', '.') }}%
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right font-medium whitespace-nowrap">{{ formatImporteCorto($org['total_importe']) }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    {{-- Sección 2: Comparativa CCAA --}}
    <section class="mb-10">
        <h2 class="text-lg font-semibold text-gray-900 mb-2">Comparativa por comunidad autónoma</h2>
        <p class="text-sm text-gray-500 mb-4">Indicadores avanzados: porcentaje de menores, media de ofertas (competencia) y crecimiento interanual ({{ $rankings['ultimo_ano'] - 1 }} vs {{ $rankings['ultimo_ano'] }}).</p>

        <div class="bg-white rounded-lg shadow p-6 mb-6">
            <x-chart id="rankingsCcaa" type="bar" height="450px" />
        </div>

        <div class="bg-white rounded-lg shadow overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-gray-600 uppercase text-xs">
                        <tr>
                            <th class="px-4 py-3 text-left">CCAA</th>
                            <th class="px-4 py-3 text-right">Contratos</th>
                            <th class="px-4 py-3 text-right">Importe total</th>
                            <th class="px-4 py-3 text-right">Imp. medio</th>
                            <th class="px-4 py-3 text-right">% Menores</th>
                            <th class="px-4 py-3 text-right">Per cápita</th>
                            <th class="px-4 py-3 text-right">Media ofertas</th>
                            <th class="px-4 py-3 text-right">YoY</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($rankings['comparativa_ccaa'] as $ccaa)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 font-medium text-gray-900">{{ $ccaa['nombre'] }}</td>
                            <td class="px-4 py-3 text-right">{{ number_format($ccaa['total_contratos'], 0, ',', '.') }}</td>
                            <td class="px-4 py-3 text-right font-medium whitespace-nowrap">{{ formatImporteCorto($ccaa['total_importe']) }}</td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">{{ formatImporteCorto($ccaa['importe_medio']) }}</td>
                            <td class="px-4 py-3 text-right">
                                @php
                                    $badgeColor = $ccaa['pct_menores'] >= 80
                                        ? 'bg-red-100 text-red-700'
                                        : ($ccaa['pct_menores'] >= 60 ? 'bg-amber-100 text-amber-700' : 'bg-gray-100 text-gray-600');
                                @endphp
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium {{ $badgeColor }}">
                                    {{ number_format($ccaa['pct_menores'], 1, ',', '.') }}%
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                @if(!empty($ccaa['gasto_per_capita']))
                                    {{ formatImporteCorto($ccaa['gasto_per_capita']) }}/hab
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right">
                                {{ $ccaa['media_ofertas'] !== null ? number_format($ccaa['media_ofertas'], 1, ',', '.') : '—' }}
                            </td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                @if($ccaa['crecimiento_yoy'] !== null)
                                    <span class="{{ $ccaa['crecimiento_yoy'] >= 0 ? 'text-green-600' : 'text-red-600' }} font-medium">
                                        {{ $ccaa['crecimiento_yoy'] >= 0 ? '+' : '' }}{{ number_format($ccaa['crecimiento_yoy'], 1, ',', '.') }}%
                                    </span>
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    {{-- Sección 3: Top organismos por importe medio --}}
    <section class="mb-10">
        <h2 class="text-lg font-semibold text-gray-900 mb-2">Organismos con mayor importe medio por contrato</h2>
        <p class="text-sm text-gray-500 mb-4">Top 30 organismos (mín. 10 contratos con importe). Señala organismos con adjudicaciones de alto valor concentradas.</p>

        <div class="bg-white rounded-lg shadow p-6 mb-6">
            <x-chart id="rankingsImporteMedio" type="bar" height="400px" />
        </div>

        <div class="bg-white rounded-lg shadow overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-gray-600 uppercase text-xs">
                        <tr>
                            <th class="px-4 py-3 text-left">#</th>
                            <th class="px-4 py-3 text-left">Organismo</th>
                            <th class="px-4 py-3 text-right">Contratos</th>
                            <th class="px-4 py-3 text-right">Importe total</th>
                            <th class="px-4 py-3 text-right">Importe medio</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($rankings['top_organismos_importe_medio'] as $i => $org)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 text-gray-400">{{ $i + 1 }}</td>
                            <td class="px-4 py-3 font-medium text-gray-900">
                                <a href="{{ route('organismos.show', $org['nif']) }}" class="hover:text-primary hover:underline">{{ Str::limit($org['nombre'], 55) }}</a>
                            </td>
                            <td class="px-4 py-3 text-right">{{ number_format($org['total_contratos'], 0, ',', '.') }}</td>
                            <td class="px-4 py-3 text-right font-medium whitespace-nowrap">{{ formatImporteCorto($org['total_importe']) }}</td>
                            <td class="px-4 py-3 text-right font-bold whitespace-nowrap text-primary">{{ formatImporteCorto($org['importe_medio']) }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    {{-- Sección 4: Top organismos con más anomalías --}}
    @if(!empty($rankings['top_organismos_anomalias']))
    <section class="mb-10">
        <h2 class="text-lg font-semibold text-gray-900 mb-2">Organismos con más anomalías detectadas</h2>
        <p class="text-sm text-gray-500 mb-4">Top 30 organismos con anomalías sin revisar. Ver <a href="{{ route('anomalias.index') }}" class="text-primary hover:underline">detalle de anomalías</a>.</p>
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-gray-600 uppercase text-xs">
                        <tr>
                            <th class="px-4 py-3 text-left">#</th>
                            <th class="px-4 py-3 text-left">Organismo</th>
                            <th class="px-4 py-3 text-right">Total anomalías</th>
                            <th class="px-4 py-3 text-right">Severidad alta</th>
                            <th class="px-4 py-3 text-right">Severidad media</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($rankings['top_organismos_anomalias'] as $i => $org)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 text-gray-400">{{ $i + 1 }}</td>
                            <td class="px-4 py-3 font-medium text-gray-900">
                                <a href="{{ route('organismos.show', $org['nif']) }}" class="hover:text-primary hover:underline">{{ Str::limit($org['nombre'], 55) }}</a>
                            </td>
                            <td class="px-4 py-3 text-right font-bold">{{ number_format($org['total_anomalias'], 0, ',', '.') }}</td>
                            <td class="px-4 py-3 text-right">
                                @if($org['anomalias_alta'] > 0)
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-700">
                                        {{ $org['anomalias_alta'] }}
                                    </span>
                                @else
                                    <span class="text-gray-400">0</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right">
                                @if($org['anomalias_media'] > 0)
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-700">
                                        {{ $org['anomalias_media'] }}
                                    </span>
                                @else
                                    <span class="text-gray-400">0</span>
                                @endif
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </section>
    @endif

    <p class="text-xs text-gray-400 text-center">
        Datos generados el {{ \Carbon\Carbon::parse($rankings['generado_at'])->format('d/m/Y H:i') }}.
        Fuente: <a href="https://contrataciondelestado.es" target="_blank" rel="noopener" class="underline hover:text-gray-600">PLACSP</a> y portales autonómicos.
    </p>

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const fmt = v => {
        if (v >= 1e9) return (v/1e9).toFixed(1) + ' MM€';
        if (v >= 1e6) return (v/1e6).toFixed(1) + ' M€';
        if (v >= 1e3) return (v/1e3).toFixed(0) + ' K€';
        return v.toFixed(0) + ' €';
    };

    // 1. Comparativa CCAA — horizontal bar: % menores + línea media ofertas
    const ccaaData = @json($rankings['comparativa_ccaa']);
    new Chart(document.getElementById('rankingsCcaa'), {
        type: 'bar',
        data: {
            labels: ccaaData.map(d => d.nombre),
            datasets: [{
                label: '% Menores',
                data: ccaaData.map(d => d.pct_menores),
                backgroundColor: ccaaData.map(d => d.pct_menores >= 80 ? 'rgba(220,38,38,0.7)' : d.pct_menores >= 60 ? 'rgba(217,119,6,0.7)' : 'rgba(37,99,235,0.7)'),
                yAxisID: 'y',
                order: 2
            }, {
                label: 'Media ofertas',
                data: ccaaData.map(d => d.media_ofertas),
                type: 'line',
                borderColor: '#16a34a',
                backgroundColor: 'rgba(22,163,74,0.1)',
                fill: false,
                tension: 0.3,
                pointRadius: 4,
                yAxisID: 'y1',
                order: 1
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { position: 'bottom' },
                tooltip: { callbacks: {
                    label: ctx => ctx.dataset.label === '% Menores'
                        ? ctx.parsed.x.toFixed(1) + '% menores'
                        : (ctx.parsed.x ? ctx.parsed.x.toFixed(1) + ' ofertas/contrato' : 'Sin datos')
                }}
            },
            scales: {
                y: { position: 'left' },
                x: { position: 'bottom', title: { display: true, text: '% Contratos menores' }, min: 0, max: 100 },
                y1: { display: false }
            }
        }
    });

    // 2. Top organismos importe medio — horizontal bar
    const imData = @json($rankings['top_organismos_importe_medio']).slice(0, 15);
    new Chart(document.getElementById('rankingsImporteMedio'), {
        type: 'bar',
        data: {
            labels: imData.map(d => d.nombre.length > 40 ? d.nombre.substring(0, 40) + '...' : d.nombre),
            datasets: [{
                label: 'Importe medio',
                data: imData.map(d => d.importe_medio),
                backgroundColor: 'rgba(124,58,237,0.7)',
                borderColor: '#7c3aed',
                borderWidth: 1
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: { callbacks: { label: ctx => fmt(ctx.parsed.x) + ' (' + imData[ctx.dataIndex].total_contratos.toLocaleString('es-ES') + ' contratos)' } }
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
