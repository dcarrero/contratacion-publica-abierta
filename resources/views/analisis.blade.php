<x-layouts.app title="Análisis — Contratación Abierta">

    <h1 class="text-2xl font-bold text-gray-900 mb-2">Análisis de la contratación pública</h1>

    {{-- Sub-navegación --}}
    @include('analisis._subnav', ['active' => 'graficas'])

    @if(!$charts)
    <div class="bg-amber-50 border border-amber-200 rounded-lg p-6 text-amber-800">
        <p class="font-medium">No hay datos de gráficas disponibles.</p>
        <p class="text-sm mt-1">Ejecuta <code class="bg-amber-100 px-1 rounded">php artisan stats:recalculate --entity=charts</code> para generar los datos.</p>
    </div>
    @else

    {{-- Evolución anual --}}
    <section class="mb-10">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Evolución anual</h2>
        <div class="bg-white rounded-lg shadow p-6">
            <x-chart id="analisisEvolucion" height="400px" />
        </div>
    </section>

    {{-- Evolución mensual --}}
    <section class="mb-10">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Evolución mensual (últimos 24 meses)</h2>
        <div class="bg-white rounded-lg shadow p-6">
            <x-chart id="analisisMensual" height="350px" />
        </div>
    </section>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-10">
        {{-- Distribución por tipo --}}
        <section>
            <h2 class="text-lg font-semibold text-gray-900 mb-4">Distribución por tipo de contrato</h2>
            <div class="bg-white rounded-lg shadow p-6">
                <x-chart id="analisisTipo" type="doughnut" height="300px" />
                <div class="mt-4 border-t pt-4">
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-50 text-gray-600 uppercase text-xs">
                                <tr>
                                    <th class="px-3 py-2 text-left">Tipo</th>
                                    <th class="px-3 py-2 text-right">Contratos</th>
                                    <th class="px-3 py-2 text-right">Importe</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach($charts['distribucion_tipo'] as $item)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-3 py-2 text-gray-900">{{ $item['label'] }}</td>
                                    <td class="px-3 py-2 text-right">{{ number_format($item['num_contratos'], 0, ',', '.') }}</td>
                                    <td class="px-3 py-2 text-right font-medium whitespace-nowrap">{{ formatImporteCorto($item['total_importe']) }}</td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </section>

        {{-- Top sectores CPV --}}
        <section>
            <h2 class="text-lg font-semibold text-gray-900 mb-4">Top 10 sectores (CPV)</h2>
            <div class="bg-white rounded-lg shadow p-6">
                <x-chart id="analisisCpv" type="bar" height="400px" />
            </div>
        </section>
    </div>

    {{-- Distribución por comunidades autónomas --}}
    <section class="mb-10">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Contratación por comunidad autónoma</h2>
        <div class="bg-white rounded-lg shadow p-6">
            <x-chart id="analisisCcaa" type="bar" height="450px" />
        </div>
    </section>

    {{-- Análisis contratos menores --}}
    <section class="mb-10">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Análisis de contratos menores — distribución por importe</h2>
        <p class="text-sm text-gray-500 mb-4">Los umbrales legales son 15.000 € (servicios/suministros) y 40.000 € (obras). Las barras <span class="text-red-600 font-medium">en rojo</span> señalan acumulaciones sospechosas justo por debajo de estos límites.</p>
        <div class="bg-white rounded-lg shadow p-6">
            <x-chart id="analisisMenores" type="bar" height="400px" />
        </div>
    </section>

    {{-- Concentración --}}
    <section class="mb-10">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Concentración de adjudicaciones</h2>
        <div class="bg-white rounded-lg shadow p-6">
            <p class="text-lg text-gray-700 mb-4">
                Los <span class="font-bold">10 mayores adjudicatarios</span> concentran el
                <span class="text-2xl font-bold text-primary">{{ number_format($charts['concentracion']['porcentaje'], 1, ',', '.') }}%</span>
                del importe total adjudicado
                (<span class="font-medium">{{ formatImporteCorto($charts['concentracion']['top10_importe']) }}</span>
                de {{ formatImporteCorto($charts['concentracion']['total_importe']) }}).
            </p>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-gray-600 uppercase text-xs">
                        <tr>
                            <th class="px-4 py-3 text-left">#</th>
                            <th class="px-4 py-3 text-left">Adjudicatario</th>
                            <th class="px-4 py-3 text-right">Contratos</th>
                            <th class="px-4 py-3 text-right">Importe</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($charts['concentracion']['top10'] as $i => $adj)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 text-gray-400">{{ $i + 1 }}</td>
                            <td class="px-4 py-3 font-medium text-gray-900">
                                <a href="{{ route('empresas.show', $adj['nif']) }}" class="hover:text-primary hover:underline">{{ Str::limit($adj['nombre'], 60) }}</a>
                            </td>
                            <td class="px-4 py-3 text-right">{{ number_format($adj['total_contratos'], 0, ',', '.') }}</td>
                            <td class="px-4 py-3 text-right font-medium whitespace-nowrap">{{ formatImporteCorto($adj['total_importe']) }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <p class="text-xs text-gray-400 text-center">
        Datos generados el {{ \Carbon\Carbon::parse($charts['generado_at'])->format('d/m/Y H:i') }}.
        Fuente: <a href="https://contrataciondelestado.es" target="_blank" rel="noopener" class="underline hover:text-gray-600">PLACSP</a> y portales autonómicos.
    </p>

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const chartColors = ['#dc2626','#2563eb','#16a34a','#d97706','#7c3aed','#db2777','#0891b2','#65a30d','#ea580c','#6366f1'];
    const fmt = v => {
        if (v >= 1e9) return (v/1e9).toFixed(1) + ' MM€';
        if (v >= 1e6) return (v/1e6).toFixed(1) + ' M€';
        if (v >= 1e3) return (v/1e3).toFixed(0) + ' K€';
        return v.toFixed(0) + ' €';
    };

    // 1. Evolución anual
    const evData = @json($charts['evolucion_anual']);
    new Chart(document.getElementById('analisisEvolucion'), {
        type: 'bar',
        data: {
            labels: evData.map(d => d.year),
            datasets: [{
                label: 'Contratos',
                data: evData.map(d => d.num_contratos),
                backgroundColor: 'rgba(220,38,38,0.7)',
                yAxisID: 'y',
                order: 2
            }, {
                label: 'Importe',
                data: evData.map(d => d.total_importe),
                type: 'line',
                borderColor: '#2563eb',
                backgroundColor: 'rgba(37,99,235,0.1)',
                fill: true,
                tension: 0.3,
                yAxisID: 'y1',
                order: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: { legend: { position: 'bottom' } },
            scales: {
                y: { position: 'left', title: { display: true, text: 'Contratos' }, ticks: { callback: v => v >= 1e6 ? (v/1e6).toFixed(0)+'M' : v >= 1e3 ? (v/1e3).toFixed(0)+'K' : v } },
                y1: { position: 'right', title: { display: true, text: 'Importe' }, grid: { drawOnChartArea: false }, ticks: { callback: v => fmt(v) } }
            }
        }
    });

    // 2. Evolución mensual
    const mesData = @json($charts['evolucion_mensual']);
    new Chart(document.getElementById('analisisMensual'), {
        type: 'line',
        data: {
            labels: mesData.map(d => d.mes),
            datasets: [{
                label: 'Contratos',
                data: mesData.map(d => d.num_contratos),
                borderColor: '#dc2626',
                backgroundColor: 'rgba(220,38,38,0.1)',
                fill: true,
                tension: 0.3,
                yAxisID: 'y'
            }, {
                label: 'Importe',
                data: mesData.map(d => d.total_importe),
                borderColor: '#2563eb',
                backgroundColor: 'rgba(37,99,235,0.1)',
                fill: true,
                tension: 0.3,
                yAxisID: 'y1'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: { legend: { position: 'bottom' } },
            scales: {
                x: { ticks: { maxRotation: 45 } },
                y: { position: 'left', title: { display: true, text: 'Contratos' }, ticks: { callback: v => v >= 1e3 ? (v/1e3).toFixed(0)+'K' : v } },
                y1: { position: 'right', title: { display: true, text: 'Importe' }, grid: { drawOnChartArea: false }, ticks: { callback: v => fmt(v) } }
            }
        }
    });

    // 3. Distribución por tipo
    const tipoData = @json($charts['distribucion_tipo']);
    new Chart(document.getElementById('analisisTipo'), {
        type: 'doughnut',
        data: {
            labels: tipoData.map(d => d.label),
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

    // 4. Top CPV
    const cpvData = @json($charts['top_cpv']);
    new Chart(document.getElementById('analisisCpv'), {
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
                tooltip: { callbacks: { label: ctx => fmt(ctx.parsed.x) + ' (' + cpvData[ctx.dataIndex].num_contratos.toLocaleString('es-ES') + ' contratos)' } }
            },
            scales: {
                x: { ticks: { callback: v => fmt(v) } }
            }
        }
    });

    // 5. Distribución por comunidades autónomas
    const ccaaData = @json($charts['distribucion_ccaa'] ?? []);
    new Chart(document.getElementById('analisisCcaa'), {
        type: 'bar',
        data: {
            labels: ccaaData.map(d => d.nombre),
            datasets: [{
                label: 'Importe adjudicado',
                data: ccaaData.map(d => d.total_importe),
                backgroundColor: 'rgba(220,38,38,0.7)'
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: { callbacks: { label: ctx => fmt(ctx.parsed.x) + ' (' + ccaaData[ctx.dataIndex].total_contratos.toLocaleString('es-ES') + ' contratos)' } }
            },
            scales: {
                x: { ticks: { callback: v => fmt(v) } }
            }
        }
    });

    // 6. Contratos menores — histograma con rangos finos
    const menoresData = @json($charts['umbral_menores']);
    // Rangos sospechosos: cerca de 15K y 40K (índices de barras que marcar en rojo)
    const rangosSospechosos = ['13-13,5K','13,5-14K','14-14,5K','14,5-15K','39-39,5K','39,5-40K'];
    new Chart(document.getElementById('analisisMenores'), {
        type: 'bar',
        data: {
            labels: menoresData.map(d => d.rango),
            datasets: [{
                label: 'Contratos menores',
                data: menoresData.map(d => d.num_contratos),
                backgroundColor: menoresData.map(d => rangosSospechosos.includes(d.rango) ? 'rgba(220,38,38,0.85)' : 'rgba(37,99,235,0.7)'),
                borderColor: menoresData.map(d => rangosSospechosos.includes(d.rango) ? '#dc2626' : '#2563eb'),
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: { callbacks: { label: ctx => ctx.parsed.y.toLocaleString('es-ES') + ' contratos' } }
            },
            scales: {
                x: { ticks: { maxRotation: 60, font: { size: 10 } } },
                y: { title: { display: true, text: 'Nº contratos' }, ticks: { callback: v => v >= 1e6 ? (v/1e6).toFixed(0)+'M' : v >= 1e3 ? (v/1e3).toFixed(0)+'K' : v } }
            }
        }
    });
});
</script>
@endpush

    @endif

</x-layouts.app>
