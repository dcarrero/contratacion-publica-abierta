<x-layouts.app title="Informes — Contratación Abierta">

    <h1 class="text-2xl font-bold text-gray-900 mb-2">Informes y exportación de datos</h1>

    @include('analisis._subnav', ['active' => 'informes'])

    {{-- Informes por CCAA --}}
    <section class="mb-10">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Informes por comunidad autónoma</h2>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
            @foreach($ccaaList as $ca)
                @php
                    $stats = collect($ccaaStats)->firstWhere('nuts', $ca->nuts);
                    $totalContratos = $stats['total_contratos'] ?? 0;
                    $totalImporte = $stats['total_importe'] ?? 0;
                @endphp
                <div class="bg-white rounded-lg shadow p-4 hover:shadow-md transition-shadow">
                    <h3 class="font-semibold text-gray-900 text-sm mb-2">{{ $ca->nombre }}</h3>
                    <div class="text-xs text-gray-500 mb-3">
                        <span>{{ number_format($totalContratos, 0, ',', '.') }} contratos</span>
                        <span class="mx-1">&middot;</span>
                        <span>{{ formatImporteCorto($totalImporte) }}</span>
                    </div>
                    <div class="flex gap-2">
                        <a href="{{ route('informes.ccaa', $ca) }}"
                           class="text-xs px-3 py-1 bg-primary text-white rounded hover:bg-primary-dark transition-colors">
                            Ver informe
                        </a>
                        <a href="{{ route('informes.ccaa.pdf', $ca) }}"
                           class="text-xs px-3 py-1 border border-gray-300 text-gray-700 rounded hover:bg-gray-50 transition-colors">
                            PDF
                        </a>
                    </div>
                </div>
            @endforeach
        </div>
    </section>

    {{-- Informe anual nacional --}}
    <section class="mb-10">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Informe anual nacional</h2>
        <div class="bg-white rounded-lg shadow p-6">
            <p class="text-sm text-gray-600 mb-4">Resumen nacional de contratación pública filtrado por año.</p>
            <div class="flex flex-wrap gap-3">
                @foreach($years as $year)
                    <div class="flex gap-1">
                        <a href="{{ route('informes.anual', ['year' => $year]) }}"
                           class="text-sm px-3 py-1 bg-primary text-white rounded hover:bg-primary-dark transition-colors">
                            {{ $year }}
                        </a>
                        <a href="{{ route('informes.anual.pdf', ['year' => $year]) }}"
                           class="text-sm px-2 py-1 border border-gray-300 text-gray-600 rounded hover:bg-gray-50 transition-colors"
                           title="Descargar PDF {{ $year }}">
                            PDF
                        </a>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- Exportación CSV --}}
    <section class="mb-10">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Exportación de datos (CSV)</h2>
        <div class="bg-white rounded-lg shadow p-6">
            <p class="text-sm text-gray-600 mb-4">
                Descarga datos en formato CSV compatible con Excel.
                Límite: {{ number_format(config('contratacion.informes.max_csv_rows', 500000), 0, ',', '.') }} filas por descarga.
            </p>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="border rounded-lg p-4">
                    <h3 class="font-semibold text-gray-900 mb-2">Contratos</h3>
                    <p class="text-xs text-gray-500 mb-3">Todos los contratos con filtros opcionales (CCAA, año, tipo, importe).</p>
                    <a href="{{ route('export.contratos') }}"
                       class="text-sm px-4 py-2 bg-green-600 text-white rounded hover:bg-green-700 transition-colors inline-block">
                        Descargar CSV
                    </a>
                </div>
                <div class="border rounded-lg p-4">
                    <h3 class="font-semibold text-gray-900 mb-2">Organismos</h3>
                    <p class="text-xs text-gray-500 mb-3">Listado de organismos contratantes con totales.</p>
                    <a href="{{ route('export.organismos') }}"
                       class="text-sm px-4 py-2 bg-green-600 text-white rounded hover:bg-green-700 transition-colors inline-block">
                        Descargar CSV
                    </a>
                </div>
                <div class="border rounded-lg p-4">
                    <h3 class="font-semibold text-gray-900 mb-2">Adjudicatarios</h3>
                    <p class="text-xs text-gray-500 mb-3">Listado de empresas adjudicatarias con totales.</p>
                    <a href="{{ route('export.adjudicatarios') }}"
                       class="text-sm px-4 py-2 bg-green-600 text-white rounded hover:bg-green-700 transition-colors inline-block">
                        Descargar CSV
                    </a>
                </div>
            </div>
        </div>
    </section>

</x-layouts.app>
