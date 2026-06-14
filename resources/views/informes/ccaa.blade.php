<x-layouts.app title="Informe {{ $comunidad->nombre }} — Contratación Abierta">

    <h1 class="text-2xl font-bold text-gray-900 mb-2">Informe: {{ $comunidad->nombre }}</h1>

    @include('analisis._subnav', ['active' => 'informes'])

    {{-- Breadcrumb --}}
    <nav class="text-sm text-gray-500 mb-6">
        <a href="{{ route('informes.index') }}" class="hover:text-primary">Informes</a>
        <span class="mx-1">/</span>
        <span class="text-gray-900">{{ $comunidad->nombre }}</span>
    </nav>

    @if(!$data)
    <div class="bg-amber-50 border border-amber-200 rounded-lg p-6 text-amber-800">
        <p class="font-medium">No hay datos disponibles para esta comunidad.</p>
        <p class="text-sm mt-1">Ejecuta <code class="bg-amber-100 px-1 rounded">php artisan stats:recalculate --entity=informes</code></p>
    </div>
    @else

    {{-- KPIs --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
        <div class="bg-white rounded-lg shadow p-4">
            <div class="text-xs text-gray-500 uppercase">Contratos</div>
            <div class="text-2xl font-bold text-gray-900">{{ number_format($data['kpis']['total_contratos'], 0, ',', '.') }}</div>
        </div>
        <div class="bg-white rounded-lg shadow p-4">
            <div class="text-xs text-gray-500 uppercase">Importe total</div>
            <div class="text-2xl font-bold text-gray-900">{{ formatImporteCorto($data['kpis']['total_importe']) }}</div>
        </div>
        <div class="bg-white rounded-lg shadow p-4">
            <div class="text-xs text-gray-500 uppercase">% Menores</div>
            <div class="text-2xl font-bold text-gray-900">{{ $data['kpis']['pct_menores'] }}%</div>
        </div>
        <div class="bg-white rounded-lg shadow p-4">
            <div class="text-xs text-gray-500 uppercase">Importe medio</div>
            <div class="text-2xl font-bold text-gray-900">{{ formatImporteCorto($data['kpis']['importe_medio']) }}</div>
        </div>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
        <div class="bg-white rounded-lg shadow p-4">
            <div class="text-xs text-gray-500 uppercase">Organismos</div>
            <div class="text-xl font-bold text-gray-900">{{ number_format($data['kpis']['total_organismos'], 0, ',', '.') }}</div>
        </div>
        <div class="bg-white rounded-lg shadow p-4">
            <div class="text-xs text-gray-500 uppercase">Adjudicatarios</div>
            <div class="text-xl font-bold text-gray-900">{{ number_format($data['kpis']['total_adjudicatarios'], 0, ',', '.') }}</div>
        </div>
        @if(!empty($data['kpis']['poblacion']))
        <div class="bg-blue-50 rounded-lg shadow p-4">
            <div class="text-xs text-blue-600 uppercase">Población (INE 2024)</div>
            <div class="text-xl font-bold text-gray-900">{{ number_format($data['kpis']['poblacion'], 0, ',', '.') }}</div>
        </div>
        <div class="bg-blue-50 rounded-lg shadow p-4">
            <div class="text-xs text-blue-600 uppercase">Gasto per cápita</div>
            <div class="text-xl font-bold text-gray-900">
                @if(!empty($data['kpis']['gasto_per_capita']))
                    {{ formatImporte($data['kpis']['gasto_per_capita']) }}/hab
                @else
                    —
                @endif
            </div>
        </div>
        @endif
    </div>

    {{-- Evolución anual --}}
    @if(!empty($data['evolucion_anual']))
    <section class="mb-8">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Evolución anual</h2>
        <div class="bg-white rounded-lg shadow overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-gray-600 uppercase text-xs">
                    <tr>
                        <th class="px-4 py-2 text-left">Año</th>
                        <th class="px-4 py-2 text-right">Contratos</th>
                        <th class="px-4 py-2 text-right">Importe</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($data['evolucion_anual'] as $row)
                    <tr class="border-t hover:bg-gray-50">
                        <td class="px-4 py-2 font-medium">{{ $row['year'] }}</td>
                        <td class="px-4 py-2 text-right">{{ number_format($row['num_contratos'], 0, ',', '.') }}</td>
                        <td class="px-4 py-2 text-right">{{ formatImporteCorto($row['total_importe']) }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
    @endif

    {{-- Distribución por tipo --}}
    @if(!empty($data['distribucion_tipo']))
    <section class="mb-8">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Distribución por tipo de contrato</h2>
        <div class="bg-white rounded-lg shadow overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-gray-600 uppercase text-xs">
                    <tr>
                        <th class="px-4 py-2 text-left">Tipo</th>
                        <th class="px-4 py-2 text-right">Contratos</th>
                        <th class="px-4 py-2 text-right">Importe</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($data['distribucion_tipo'] as $row)
                    <tr class="border-t hover:bg-gray-50">
                        <td class="px-4 py-2">{{ $row['label'] }}</td>
                        <td class="px-4 py-2 text-right">{{ number_format($row['num_contratos'], 0, ',', '.') }}</td>
                        <td class="px-4 py-2 text-right">{{ formatImporteCorto($row['total_importe']) }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
    @endif

    {{-- Top CPV --}}
    @if(!empty($data['top_cpv']))
    <section class="mb-8">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Principales sectores (CPV)</h2>
        <div class="bg-white rounded-lg shadow overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-gray-600 uppercase text-xs">
                    <tr>
                        <th class="px-4 py-2 text-left">CPV</th>
                        <th class="px-4 py-2 text-left">Descripción</th>
                        <th class="px-4 py-2 text-right">Contratos</th>
                        <th class="px-4 py-2 text-right">Importe</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($data['top_cpv'] as $row)
                    <tr class="border-t hover:bg-gray-50">
                        <td class="px-4 py-2 font-mono text-xs">{{ $row['cpv2'] }}</td>
                        <td class="px-4 py-2">{{ $row['descripcion'] }}</td>
                        <td class="px-4 py-2 text-right">{{ number_format($row['num_contratos'], 0, ',', '.') }}</td>
                        <td class="px-4 py-2 text-right">{{ formatImporteCorto($row['total_importe']) }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
    @endif

    {{-- Top adjudicatarios --}}
    @if(!empty($data['top_adjudicatarios']))
    <section class="mb-8">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Principales adjudicatarios</h2>
        <div class="bg-white rounded-lg shadow overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-gray-600 uppercase text-xs">
                    <tr>
                        <th class="px-4 py-2 text-left">#</th>
                        <th class="px-4 py-2 text-left">Empresa</th>
                        <th class="px-4 py-2 text-right">Contratos</th>
                        <th class="px-4 py-2 text-right">Importe</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($data['top_adjudicatarios'] as $i => $row)
                    <tr class="border-t hover:bg-gray-50">
                        <td class="px-4 py-2 text-gray-500">{{ $i + 1 }}</td>
                        <td class="px-4 py-2">
                            <a href="{{ route('empresas.show', $row['nif']) }}" class="text-primary hover:underline">{{ $row['nombre'] }}</a>
                            <span class="text-xs text-gray-400 ml-1">{{ $row['nif'] }}</span>
                        </td>
                        <td class="px-4 py-2 text-right">{{ number_format($row['total_contratos'], 0, ',', '.') }}</td>
                        <td class="px-4 py-2 text-right">{{ formatImporteCorto($row['total_importe']) }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
    @endif

    {{-- Top organismos --}}
    @if(!empty($data['top_organismos']))
    <section class="mb-8">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Principales organismos contratantes</h2>
        <div class="bg-white rounded-lg shadow overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-gray-600 uppercase text-xs">
                    <tr>
                        <th class="px-4 py-2 text-left">#</th>
                        <th class="px-4 py-2 text-left">Organismo</th>
                        <th class="px-4 py-2 text-right">Contratos</th>
                        <th class="px-4 py-2 text-right">Importe</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($data['top_organismos'] as $i => $row)
                    <tr class="border-t hover:bg-gray-50">
                        <td class="px-4 py-2 text-gray-500">{{ $i + 1 }}</td>
                        <td class="px-4 py-2">
                            <a href="{{ route('organismos.show', $row['nif']) }}" class="text-primary hover:underline">{{ $row['nombre'] }}</a>
                        </td>
                        <td class="px-4 py-2 text-right">{{ number_format($row['total_contratos'], 0, ',', '.') }}</td>
                        <td class="px-4 py-2 text-right">{{ formatImporteCorto($row['total_importe']) }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
    @endif

    {{-- Anomalías resumen --}}
    @if(!empty($data['anomalias_resumen']) && $data['anomalias_resumen']['total'] > 0)
    <section class="mb-8">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Resumen de anomalías detectadas</h2>
        <div class="bg-white rounded-lg shadow p-6">
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                <div class="text-center">
                    <div class="text-2xl font-bold text-red-600">{{ $data['anomalias_resumen']['total'] }}</div>
                    <div class="text-xs text-gray-500">Total</div>
                </div>
                <div class="text-center">
                    <div class="text-2xl font-bold text-orange-600">{{ $data['anomalias_resumen']['fraccionamiento'] }}</div>
                    <div class="text-xs text-gray-500">Fraccionamiento</div>
                </div>
                <div class="text-center">
                    <div class="text-2xl font-bold text-yellow-600">{{ $data['anomalias_resumen']['concentracion'] }}</div>
                    <div class="text-xs text-gray-500">Concentración</div>
                </div>
                <div class="text-center">
                    <div class="text-2xl font-bold text-purple-600">{{ $data['anomalias_resumen']['pico_temporal'] }}</div>
                    <div class="text-xs text-gray-500">Pico temporal</div>
                </div>
            </div>
        </div>
    </section>
    @endif

    {{-- Botón PDF --}}
    <div class="flex gap-3 mb-8">
        <a href="{{ route('informes.ccaa.pdf', $comunidad) }}"
           class="px-6 py-2 bg-primary text-white rounded-lg hover:bg-primary-dark transition-colors inline-flex items-center gap-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
            Descargar PDF
        </a>
        <a href="{{ route('export.contratos', ['ccaa' => $comunidad->nuts]) }}"
           class="px-6 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors inline-flex items-center gap-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
            Exportar contratos CSV
        </a>
    </div>

    <p class="text-xs text-gray-400">Generado: {{ $data['generado_at'] ?? 'N/A' }}</p>

    @endif

</x-layouts.app>
