<x-layouts.app :title="$empresa->nombre . ' — Contratación Abierta'">

    {{-- Breadcrumb --}}
    <nav class="text-sm text-gray-500 mb-4">
        <a href="{{ route('empresas.index') }}" class="hover:text-primary">Empresas</a>
        <span class="mx-1">/</span>
        <span class="text-gray-700">{{ $empresa->nif }}</span>
    </nav>

    {{-- Header --}}
    <div class="mb-6">
        <h1 class="text-xl sm:text-2xl font-bold text-gray-900 leading-tight">{{ $empresa->nombre }}</h1>
        <div class="mt-2 flex flex-wrap items-center gap-3 text-sm text-gray-500">
            <span class="font-mono">{{ $empresa->nif }}</span>
            @if($empresa->es_pyme)
                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-blue-100 text-blue-700">PYME</span>
            @endif
            @if(Route::has('empresas.informe.pdf'))
            <a href="{{ route('empresas.informe.pdf', $empresa->nif) }}"
               class="inline-flex items-center gap-1.5 px-3 py-1 rounded-lg text-xs font-semibold bg-red-50 text-primary hover:bg-red-100 transition">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                Descargar informe PDF
            </a>
            @endif
        </div>

        {{-- Seguir empresa --}}
        <form action="{{ route('alertas.suscribir') }}" method="POST" class="mt-3 flex flex-wrap items-center gap-2">
            @csrf
            <input type="hidden" name="tipo" value="adjudicatario">
            <input type="hidden" name="filtro_valor" value="{{ $empresa->nif }}">
            <input type="hidden" name="filtro_nombre" value="{{ $empresa->nombre }}">
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

    {{-- Aliases --}}
    @if($ficha['aliases']->isNotEmpty())
    <div class="bg-amber-50 border border-amber-200 rounded-lg p-4 mb-6 text-sm">
        <p class="text-amber-800">
            <span class="font-medium">Este adjudicatario aparece también como:</span>
            @foreach($ficha['aliases'] as $alias)
                <span class="text-amber-700">{{ $alias->nombre_variante }} ({{ $alias->veces_visto }})</span>@if(!$loop->last), @endif
            @endforeach
        </p>
    </div>
    @endif

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
            <p class="text-xs text-gray-500 uppercase tracking-wide">Organismos</p>
            <p class="text-2xl font-bold text-gray-900 mt-1">{{ number_format($ficha['kpis']['organismos_distintos'], 0, ',', '.') }}</p>
        </div>
        <div class="bg-white rounded-lg shadow p-5">
            <p class="text-xs text-gray-500 uppercase tracking-wide">Mayor contrato</p>
            <p class="text-2xl font-bold text-gray-900 mt-1">{{ formatImporteCorto($ficha['kpis']['contrato_mayor']) }}</p>
        </div>
    </section>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
        {{-- Top 10 organismos --}}
        <section>
            <h2 class="text-lg font-semibold text-gray-900 mb-4">Top 10 organismos contratantes</h2>
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
                            @foreach($ficha['top_organismos'] as $i => $item)
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-3 text-gray-400 text-xs">{{ $i + 1 }}</td>
                                <td class="px-4 py-3">
                                    @if($item->organismo)
                                        <a href="{{ route('organismos.show', $item->organismo->nif) }}" class="text-gray-900 hover:text-primary hover:underline">
                                            {{ Str::limit($item->organismo->nombre, 50) }}
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
                <x-chart id="empChartTipo" type="doughnut" height="250px" />
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
            <x-chart id="empChartEvolucion" height="280px" />
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

    {{-- Contratos --}}
    <section>
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Contratos</h2>
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-gray-600 uppercase text-xs">
                        <tr>
                            <th class="px-4 py-3 text-left">Fecha</th>
                            <th class="px-4 py-3 text-left">Objeto</th>
                            <th class="px-4 py-3 text-left hidden md:table-cell">Organismo</th>
                            <th class="px-4 py-3 text-right">Importe</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($contratos as $contrato)
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
                                @if($contrato->organismo)
                                    <a href="{{ route('organismos.show', $contrato->organismo->nif) }}" class="hover:text-primary hover:underline">
                                        {{ Str::limit($contrato->organismo->nombre, 40) }}
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

        @if($contratos->hasPages())
            <div class="mt-6">
                {{ $contratos->links() }}
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
    new Chart(document.getElementById('empChartEvolucion'), {
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
    new Chart(document.getElementById('empChartTipo'), {
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
