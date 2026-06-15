<x-layouts.app title="Radiografía de {{ $provincia->nombre }}: contratación pública — Contratación Abierta">

    <div class="max-w-5xl mx-auto">
        {{-- Breadcrumb --}}
        <nav class="text-sm text-gray-500 mb-4">
            <a href="{{ route('radiografia.index') }}" class="hover:text-primary">Radiografía</a>
            <span class="mx-1">/</span>
            <span class="text-gray-900">{{ $provincia->nombre }}</span>
        </nav>

        <h1 class="text-2xl font-bold text-gray-900 mb-1">
            Radiografía de {{ $provincia->nombre }}@if($year) <span class="text-primary">{{ $year }}</span>@endif
        </h1>
        <p class="text-gray-500 mb-4">
            Contratación pública adjudicada en la provincia
            @if($data['comunidad']) &bull; {{ $data['comunidad'] }} @endif
        </p>

        {{-- Selector de año --}}
        @if(!empty($data['anios_disponibles']))
        <div class="flex flex-wrap gap-2 mb-6 text-sm">
            <a href="{{ route('radiografia.show', $slug) }}"
               class="px-3 py-1 rounded-full border {{ !$year ? 'bg-primary text-white border-primary' : 'bg-white text-gray-600 border-gray-300 hover:border-primary' }}">
                Todos
            </a>
            @foreach($data['anios_disponibles'] as $y)
            <a href="{{ route('radiografia.show', ['slug' => $slug, 'year' => $y]) }}"
               class="px-3 py-1 rounded-full border {{ $year === $y ? 'bg-primary text-white border-primary' : 'bg-white text-gray-600 border-gray-300 hover:border-primary' }}">
                {{ $y }}
            </a>
            @endforeach
        </div>
        @endif

        {{-- Comparación con el año anterior (YoY) --}}
        @if($year && !empty($data['comparativa']))
        @php($cmp = $data['comparativa'])
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
            @foreach([
                ['Contratos', $cmp['contratos'], false],
                ['Importe adjudicado', $cmp['importe'], true],
            ] as [$label, $m, $esImporte])
            <div class="bg-white rounded-lg shadow p-4">
                <div class="text-xs text-gray-500 uppercase">{{ $label }} {{ $year }}</div>
                <div class="text-xl font-bold text-gray-900">
                    {{ $esImporte ? formatImporteCorto($m['actual']) : number_format($m['actual'], 0, ',', '.') }}
                </div>
                @if($m['delta_pct'] !== null)
                <div class="text-xs mt-1 {{ $m['delta_pct'] >= 0 ? 'text-green-600' : 'text-red-600' }}">
                    {{ $m['delta_pct'] >= 0 ? '▲' : '▼' }} {{ number_format(abs($m['delta_pct']), 1, ',', '.') }}% vs {{ $cmp['year_anterior'] }}
                </div>
                @else
                <div class="text-xs mt-1 text-gray-400">sin dato {{ $cmp['year_anterior'] }}</div>
                @endif
            </div>
            @endforeach
            <div class="bg-white rounded-lg shadow p-4">
                <div class="text-xs text-gray-500 uppercase">Por habitante {{ $year }}</div>
                <div class="text-xl font-bold text-gray-900">
                    {{ $cmp['per_capita']['actual'] !== null ? formatImporte($cmp['per_capita']['actual']) : '—' }}
                </div>
                @if($cmp['per_capita']['anterior'] !== null)
                <div class="text-xs mt-1 text-gray-400">{{ formatImporte($cmp['per_capita']['anterior']) }} en {{ $cmp['year_anterior'] }}</div>
                @endif
            </div>
        </div>
        @endif

        @if($data['kpis']['total_contratos'] === 0)
        <div class="bg-amber-50 border border-amber-200 rounded-lg p-6 text-amber-800">
            <p class="font-medium">No hay contratos geolocalizados en esta provincia.</p>
            <p class="text-sm mt-1">No todos los contratos incluyen el código territorial (NUTS) a nivel provincial.</p>
        </div>
        @else

        {{-- Gancho: per cápita destacado --}}
        @if(!empty($data['kpis']['gasto_per_capita']))
        <div class="bg-primary text-white rounded-xl p-5 mb-6 flex flex-wrap items-baseline gap-x-3">
            <span class="text-3xl font-extrabold">{{ formatImporte($data['kpis']['gasto_per_capita']) }}</span>
            <span class="text-red-100">por habitante en contratación pública adjudicada</span>
            <span class="text-red-200 text-sm w-full mt-1">
                {{ formatImporteCorto($data['kpis']['total_importe']) }} entre {{ number_format($data['kpis']['poblacion'], 0, ',', '.') }} habitantes (padrón INE)
            </span>
        </div>
        @endif

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
                <div class="text-xs text-gray-500 uppercase">Organismos</div>
                <div class="text-2xl font-bold text-gray-900">{{ number_format($data['kpis']['total_organismos'], 0, ',', '.') }}</div>
            </div>
            <div class="bg-white rounded-lg shadow p-4">
                <div class="text-xs text-gray-500 uppercase">Adjudicatarios</div>
                <div class="text-2xl font-bold text-gray-900">{{ number_format($data['kpis']['total_adjudicatarios'], 0, ',', '.') }}</div>
            </div>
        </div>

        {{-- Evolución anual --}}
        @if(!empty($data['evolucion_anual']))
        <h2 class="text-lg font-semibold text-gray-900 mb-3">Evolución anual</h2>
        <div class="bg-white rounded-lg shadow overflow-hidden mb-8">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-500 uppercase text-xs">
                    <tr>
                        <th class="text-left px-4 py-2">Año</th>
                        <th class="text-right px-4 py-2">Contratos</th>
                        <th class="text-right px-4 py-2">Importe</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach(array_slice($data['evolucion_anual'], -10) as $row)
                    <tr>
                        <td class="px-4 py-2">{{ $row['year'] }}</td>
                        <td class="px-4 py-2 text-right">{{ number_format($row['num_contratos'], 0, ',', '.') }}</td>
                        <td class="px-4 py-2 text-right">{{ formatImporteCorto($row['total_importe']) }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif

        {{-- Top adjudicatarios --}}
        @if(!empty($data['top_adjudicatarios']))
        <h2 class="text-lg font-semibold text-gray-900 mb-3">Principales adjudicatarios</h2>
        <div class="bg-white rounded-lg shadow overflow-hidden mb-8">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-500 uppercase text-xs">
                    <tr>
                        <th class="text-left px-4 py-2">#</th>
                        <th class="text-left px-4 py-2">Empresa</th>
                        <th class="text-right px-4 py-2">Contratos</th>
                        <th class="text-right px-4 py-2">Importe</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach(array_slice($data['top_adjudicatarios'], 0, 15) as $i => $row)
                    <tr>
                        <td class="px-4 py-2 text-gray-400">{{ $i + 1 }}</td>
                        <td class="px-4 py-2">
                            <a href="{{ route('empresas.show', $row['nif']) }}" class="text-primary hover:underline">{{ $row['nombre'] }}</a>
                        </td>
                        <td class="px-4 py-2 text-right">{{ number_format($row['total_contratos'], 0, ',', '.') }}</td>
                        <td class="px-4 py-2 text-right">{{ formatImporteCorto($row['total_importe']) }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif

        {{-- Top organismos --}}
        @if(!empty($data['top_organismos']))
        <h2 class="text-lg font-semibold text-gray-900 mb-3">Principales organismos contratantes</h2>
        <div class="bg-white rounded-lg shadow overflow-hidden mb-8">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-500 uppercase text-xs">
                    <tr>
                        <th class="text-left px-4 py-2">#</th>
                        <th class="text-left px-4 py-2">Organismo</th>
                        <th class="text-right px-4 py-2">Contratos</th>
                        <th class="text-right px-4 py-2">Importe</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach(array_slice($data['top_organismos'], 0, 15) as $i => $row)
                    <tr>
                        <td class="px-4 py-2 text-gray-400">{{ $i + 1 }}</td>
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
        @endif

        {{-- Fuente / disclaimer --}}
        <p class="text-xs text-gray-400 mt-8">
            Solo se incluyen los contratos con código territorial (NUTS) a nivel provincial. Datos de fuentes
            oficiales (PLACSP y portales de datos abiertos); pueden contener errores o no estar completamente
            actualizados. Consulte siempre las fuentes oficiales. No es un sitio web oficial.
        </p>
        @endif
    </div>

</x-layouts.app>
