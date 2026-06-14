<x-layouts.app title="Contratación Abierta — Dashboard">

    {{-- KPI Cards --}}
    <section class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-10">
        <div class="bg-white rounded-lg shadow p-6">
            <p class="text-sm text-gray-500 uppercase tracking-wide">Contratos</p>
            <p class="text-3xl font-bold text-gray-900 mt-1">{{ number_format($kpis['total_contratos'], 0, ',', '.') }}</p>
        </div>
        <div class="bg-white rounded-lg shadow p-6">
            <p class="text-sm text-gray-500 uppercase tracking-wide">Volumen adjudicado</p>
            <p class="text-3xl font-bold text-primary mt-1">{{ formatImporteCorto($kpis['volumen']) }}</p>
        </div>
        <div class="bg-white rounded-lg shadow p-6">
            <p class="text-sm text-gray-500 uppercase tracking-wide">Organismos</p>
            <p class="text-3xl font-bold text-gray-900 mt-1">{{ number_format($kpis['total_organismos'], 0, ',', '.') }}</p>
        </div>
        <div class="bg-white rounded-lg shadow p-6">
            <p class="text-sm text-gray-500 uppercase tracking-wide">Adjudicatarios</p>
            <p class="text-3xl font-bold text-gray-900 mt-1">{{ number_format($kpis['total_adjudicatarios'], 0, ',', '.') }}</p>
        </div>
    </section>

    {{-- Gráficas --}}
    @if($charts)
    <section class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-10">
        {{-- Evolución anual --}}
        <div class="bg-white rounded-lg shadow p-6">
            <h2 class="text-lg font-semibold text-gray-900 mb-4">Evolución anual</h2>
            <x-chart id="chartEvolucion" height="300px" />
        </div>

        {{-- Distribución por comunidades autónomas --}}
        <div class="bg-white rounded-lg shadow p-6">
            <h2 class="text-lg font-semibold text-gray-900 mb-4">Contratación por comunidad autónoma</h2>
            <x-chart id="chartCcaa" type="bar" height="300px" />
        </div>
    </section>

    <section class="mb-10">
        {{-- Top 10 sectores CPV --}}
        <div class="bg-white rounded-lg shadow p-6">
            <h2 class="text-lg font-semibold text-gray-900 mb-4">Top 10 sectores (CPV)</h2>
            <x-chart id="chartCpv" type="bar" height="350px" />
        </div>
    </section>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-10">
        {{-- Top 10 Empresas --}}
        <section>
            <h2 class="text-lg font-semibold text-gray-900 mb-4">Top 10 adjudicatarios por importe</h2>
            <div class="bg-white rounded-lg shadow overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-gray-600 uppercase text-xs">
                            <tr>
                                <th class="px-4 py-3 text-left">#</th>
                                <th class="px-4 py-3 text-left">Nombre</th>
                                <th class="px-4 py-3 text-left">NIF</th>
                                <th class="px-4 py-3 text-right">Contratos</th>
                                <th class="px-4 py-3 text-right">Importe</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach($topEmpresas as $i => $empresa)
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-3 text-gray-500">{{ $i + 1 }}</td>
                                <td class="px-4 py-3 font-medium text-gray-900 max-w-xs truncate">
                                    <a href="{{ route('empresas.show', $empresa->nif) }}" class="hover:text-primary hover:underline">{{ $empresa->nombre }}</a>
                                </td>
                                <td class="px-4 py-3 text-gray-500 font-mono text-xs">{{ $empresa->nif }}</td>
                                <td class="px-4 py-3 text-right">{{ number_format($empresa->total_contratos, 0, ',', '.') }}</td>
                                <td class="px-4 py-3 text-right font-medium">{{ formatImporteCorto($empresa->total_importe) }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        {{-- Top 10 Organismos --}}
        <section>
            <h2 class="text-lg font-semibold text-gray-900 mb-4">Top 10 organismos por importe</h2>
            <div class="bg-white rounded-lg shadow overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-gray-600 uppercase text-xs">
                            <tr>
                                <th class="px-4 py-3 text-left">#</th>
                                <th class="px-4 py-3 text-left">Nombre</th>
                                <th class="px-4 py-3 text-left">NIF</th>
                                <th class="px-4 py-3 text-right">Contratos</th>
                                <th class="px-4 py-3 text-right">Importe</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach($topOrganismos as $i => $org)
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-3 text-gray-500">{{ $i + 1 }}</td>
                                <td class="px-4 py-3 font-medium text-gray-900 max-w-xs truncate">
                                    <a href="{{ route('organismos.show', $org->nif) }}" class="hover:text-primary hover:underline">{{ $org->nombre }}</a>
                                </td>
                                <td class="px-4 py-3 text-gray-500 font-mono text-xs">{{ $org->nif }}</td>
                                <td class="px-4 py-3 text-right">{{ number_format($org->total_contratos, 0, ',', '.') }}</td>
                                <td class="px-4 py-3 text-right font-medium">{{ formatImporteCorto($org->total_importe) }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </div>

    {{-- Últimos 20 contratos --}}
    <section class="mb-10">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Últimos 20 contratos publicados</h2>
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-gray-600 uppercase text-xs">
                        <tr>
                            <th class="px-4 py-3 text-left">Fecha</th>
                            <th class="px-4 py-3 text-left">Objeto</th>
                            <th class="px-4 py-3 text-left">Organismo</th>
                            <th class="px-4 py-3 text-right">Importe</th>
                            <th class="px-4 py-3 text-center">PLACSP</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($ultimosContratos as $contrato)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 text-gray-500 whitespace-nowrap">{{ formatFecha($contrato->fecha_publicacion ?? $contrato->fecha_adjudicacion ?? $contrato->fecha_formalizacion) }}</td>
                            <td class="px-4 py-3 text-gray-900 max-w-md">
                                <a href="{{ route('contratos.show', $contrato->placsp_id) }}" title="{{ $contrato->objeto }}" class="hover:text-primary hover:underline">{{ Str::limit($contrato->objeto, 80) }}</a>
                            </td>
                            <td class="px-4 py-3 text-gray-600 max-w-xs truncate">{{ $contrato->organismo?->nombre ?? '—' }}</td>
                            <td class="px-4 py-3 text-right font-medium whitespace-nowrap">{{ formatImporte($contrato->importe_adjudicacion) }}</td>
                            <td class="px-4 py-3 text-center">
                                @if($contrato->url_placsp)
                                <a href="{{ $contrato->url_placsp }}" target="_blank" rel="noopener" class="text-primary hover:text-primary-oscuro underline text-xs">
                                    Ver
                                </a>
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </section>


@if($charts)
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

    // Evolución anual (últimos 8 años)
    const evData = @json($charts['evolucion_anual']);
    const evRecent = evData.slice(-8);
    new Chart(document.getElementById('chartEvolucion'), {
        type: 'bar',
        data: {
            labels: evRecent.map(d => d.year),
            datasets: [{
                label: 'Contratos',
                data: evRecent.map(d => d.num_contratos),
                backgroundColor: 'rgba(220,38,38,0.7)',
                yAxisID: 'y',
                order: 2
            }, {
                label: 'Importe',
                data: evRecent.map(d => d.total_importe),
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
                y: { position: 'left', ticks: { callback: v => v >= 1e6 ? (v/1e6).toFixed(0)+'M' : v >= 1e3 ? (v/1e3).toFixed(0)+'K' : v } },
                y1: { position: 'right', grid: { drawOnChartArea: false }, ticks: { callback: v => fmt(v) } }
            }
        }
    });

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
