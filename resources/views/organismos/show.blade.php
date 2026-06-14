<x-layouts.app :title="$organismo->nombre . ' — Contratación Abierta'">

    {{-- Breadcrumb --}}
    <nav class="text-sm text-gray-500 mb-4">
        <a href="{{ route('organismos.index') }}" class="hover:text-primary">Organismos</a>
        <span class="mx-1">/</span>
        <span class="text-gray-700">{{ $organismo->nif }}</span>
    </nav>

    {{-- Header --}}
    <div class="mb-6">
        <h1 class="text-xl sm:text-2xl font-bold text-gray-900 leading-tight">{{ $organismo->nombre }}</h1>
        <div class="mt-2 flex flex-wrap items-center gap-3 text-sm text-gray-500">
            <span class="font-mono">{{ $organismo->nif }}</span>
            @if($organismo->tipo)
                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-600">{{ ucfirst($organismo->tipo) }}</span>
            @endif
            @if($organismo->dir3)
                <span class="text-xs">DIR3: {{ $organismo->dir3 }}</span>
            @endif
            @if($organismo->url_perfil_placsp)
                <a href="{{ $organismo->url_perfil_placsp }}" target="_blank" rel="noopener" class="text-primary hover:underline text-xs">Ver en PLACSP</a>
            @endif
        </div>

        {{-- Seguir organismo --}}
        <form action="{{ route('alertas.suscribir') }}" method="POST" class="mt-3 flex flex-wrap items-center gap-2">
            @csrf
            <input type="hidden" name="tipo" value="organismo">
            <input type="hidden" name="filtro_valor" value="{{ $organismo->nif }}">
            <input type="hidden" name="filtro_nombre" value="{{ $organismo->nombre }}">
            <input type="email" name="email" placeholder="tu@email.com" required
                   class="rounded-lg border-gray-300 text-sm px-3 py-1.5 w-48 focus:ring-primary focus:border-primary @error('email') border-red-500 @enderror">
            <button type="submit" class="inline-flex items-center px-3 py-1.5 bg-primary text-white rounded-lg hover:bg-primary-dark transition text-sm font-medium">
                Seguir
            </button>
            @error('email')
                <span class="text-red-500 text-xs w-full">{{ $message }}</span>
            @enderror
        </form>
    </div>

    {{-- KPIs --}}
    <section class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
        <div class="bg-white rounded-lg shadow p-5">
            <p class="text-xs text-gray-500 uppercase tracking-wide">Contratos</p>
            <p class="text-2xl font-bold text-gray-900 mt-1">{{ number_format($ficha['kpis']['total_contratos'], 0, ',', '.') }}</p>
        </div>
        <div class="bg-white rounded-lg shadow p-5">
            <p class="text-xs text-gray-500 uppercase tracking-wide">Importe total</p>
            <p class="text-2xl font-bold text-primary mt-1">{{ formatImporteCorto($ficha['kpis']['importe_total']) }}</p>
        </div>
        <div class="bg-white rounded-lg shadow p-5">
            <p class="text-xs text-gray-500 uppercase tracking-wide">Adjudicatarios</p>
            <p class="text-2xl font-bold text-gray-900 mt-1">{{ number_format($ficha['kpis']['adjudicatarios_distintos'], 0, ',', '.') }}</p>
        </div>
        <div class="bg-white rounded-lg shadow p-5">
            <p class="text-xs text-gray-500 uppercase tracking-wide">% Contratos menores</p>
            <p class="text-2xl font-bold text-gray-900 mt-1">{{ $ficha['kpis']['pct_menores'] }}%</p>
        </div>
    </section>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
        {{-- Top 10 adjudicatarios --}}
        <section>
            <h2 class="text-lg font-semibold text-gray-900 mb-4">Top 10 adjudicatarios</h2>
            <div class="bg-white rounded-lg shadow overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-gray-600 uppercase text-xs">
                            <tr>
                                <th class="px-4 py-3 text-left">#</th>
                                <th class="px-4 py-3 text-left">Nombre</th>
                                <th class="px-4 py-3 text-right">Contratos</th>
                                <th class="px-4 py-3 text-right">Importe</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach($ficha['top_adjudicatarios'] as $i => $item)
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-3 text-gray-400 text-xs">{{ $i + 1 }}</td>
                                <td class="px-4 py-3">
                                    @if($item->adjudicatario)
                                        <a href="{{ route('empresas.show', $item->adjudicatario->nif) }}" class="text-gray-900 hover:text-primary hover:underline">
                                            {{ Str::limit($item->adjudicatario->nombre, 50) }}
                                        </a>
                                    @else
                                        <span class="text-gray-400">—</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right">{{ number_format($item->num_contratos, 0, ',', '.') }}</td>
                                <td class="px-4 py-3 text-right font-medium whitespace-nowrap">{{ formatImporteCorto($item->total_importe) }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        {{-- Distribución por tipo --}}
        <section>
            <h2 class="text-lg font-semibold text-gray-900 mb-4">Distribución por tipo de contrato</h2>
            <div class="bg-white rounded-lg shadow p-6">
                @if($ficha['distribucion_tipo']->count() > 1)
                <x-chart id="orgChartTipo" type="doughnut" height="250px" />
                <div class="mt-4 border-t pt-4">
                @endif
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-gray-600 uppercase text-xs">
                            <tr>
                                <th class="px-4 py-3 text-left">Tipo</th>
                                <th class="px-4 py-3 text-right">Contratos</th>
                                <th class="px-4 py-3 text-right">Importe</th>
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
                @if($ficha['distribucion_tipo']->count() > 1)
                </div>
                @endif
            </div>
        </section>
    </div>

    {{-- Evolución anual --}}
    @if($ficha['evolucion_anual']->isNotEmpty())
    <section class="mb-8">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Evolución anual</h2>
        <div class="bg-white rounded-lg shadow p-6">
            <x-chart id="orgChartEvolucion" height="280px" />
            @if($ficha['evolucion_anual']->count() > 2)
            <div class="mt-4 border-t pt-4">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-gray-600 uppercase text-xs">
                            <tr>
                                <th class="px-4 py-3 text-left">Año</th>
                                <th class="px-4 py-3 text-right">Contratos</th>
                                <th class="px-4 py-3 text-right">Importe total</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach($ficha['evolucion_anual'] as $item)
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-3 text-gray-900 font-medium">{{ $item->year }}</td>
                                <td class="px-4 py-3 text-right">{{ number_format($item->num_contratos, 0, ',', '.') }}</td>
                                <td class="px-4 py-3 text-right font-medium whitespace-nowrap">{{ formatImporteCorto($item->total_importe) }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
            @endif
        </div>
    </section>
    @endif

    {{-- Últimos contratos --}}
    <section>
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Últimos contratos</h2>
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-gray-600 uppercase text-xs">
                        <tr>
                            <th class="px-4 py-3 text-left">Fecha</th>
                            <th class="px-4 py-3 text-left">Objeto</th>
                            <th class="px-4 py-3 text-left hidden md:table-cell">Adjudicatario</th>
                            <th class="px-4 py-3 text-right">Importe</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($ultimosContratos as $contrato)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 text-gray-500 text-xs whitespace-nowrap">{{ formatFecha($contrato->fecha_publicacion ?? $contrato->fecha_adjudicacion ?? $contrato->fecha_formalizacion) }}</td>
                            <td class="px-4 py-3">
                                <a href="{{ route('contratos.show', $contrato->placsp_id) }}" class="text-gray-900 hover:text-primary hover:underline">
                                    {{ Str::limit($contrato->objeto, 80) }}
                                </a>
                                @if($contrato->es_menor)
                                    <span class="ml-1 inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-amber-100 text-amber-700">Menor</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-gray-600 text-xs max-w-xs truncate hidden md:table-cell">
                                @if($contrato->adjudicatario)
                                    <a href="{{ route('empresas.show', $contrato->adjudicatario->nif) }}" class="hover:text-primary hover:underline">
                                        {{ Str::limit($contrato->adjudicatario->nombre, 40) }}
                                    </a>
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right font-medium whitespace-nowrap">{{ formatImporteCorto($contrato->importe_adjudicacion) }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        @if($ultimosContratos->hasPages())
            <div class="mt-6">
                {{ $ultimosContratos->links() }}
            </div>
        @endif
    </section>

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
    const tipos = @json($tipos_contrato);

    @if($ficha['evolucion_anual']->isNotEmpty())
    const evData = @json($ficha['evolucion_anual']->values());
    new Chart(document.getElementById('orgChartEvolucion'), {
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
                y: { position: 'left', ticks: { callback: v => v >= 1e6 ? (v/1e6).toFixed(0)+'M' : v >= 1e3 ? (v/1e3).toFixed(0)+'K' : v } },
                y1: { position: 'right', grid: { drawOnChartArea: false }, ticks: { callback: v => fmt(v) } }
            }
        }
    });
    @endif

    @if($ficha['distribucion_tipo']->count() > 1)
    const tipoData = @json($ficha['distribucion_tipo']->values());
    new Chart(document.getElementById('orgChartTipo'), {
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
