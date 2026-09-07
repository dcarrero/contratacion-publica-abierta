@php
    $metaDesc = 'Comparación de contratación pública entre '.$pa->nombre.' y '.$pb->nombre
        .': gasto total, importe por habitante, % de contratos menores y principales adjudicatarios.';
    $fmt = function ($v, $type) {
        if ($v === null) {
            return '—';
        }

        return match ($type) {
            'imp' => formatImporteCorto($v),
            'eur' => formatImporte($v),
            'pct' => $v.'%',
            default => number_format($v, 0, ',', '.'),
        };
    };
    $metricas = [
        ['Contratos', $da['kpis']['total_contratos'], $db['kpis']['total_contratos'], 'num'],
        ['Importe total adjudicado', $da['kpis']['total_importe'], $db['kpis']['total_importe'], 'imp'],
        ['Importe por habitante', $da['kpis']['gasto_per_capita'], $db['kpis']['gasto_per_capita'], 'eur'],
        ['Población (INE)', $da['kpis']['poblacion'], $db['kpis']['poblacion'], 'num'],
        ['% contratos menores', $da['kpis']['pct_menores'], $db['kpis']['pct_menores'], 'pct'],
        ['Organismos', $da['kpis']['total_organismos'], $db['kpis']['total_organismos'], 'num'],
        ['Adjudicatarios', $da['kpis']['total_adjudicatarios'], $db['kpis']['total_adjudicatarios'], 'num'],
        ['Importe medio', $da['kpis']['importe_medio'], $db['kpis']['importe_medio'], 'imp'],
    ];
@endphp
<x-layouts.app :title="$pa->nombre . ' vs ' . $pb->nombre . ' — Comparativa de contratación pública'" :metaDescription="$metaDesc">

    <x-seo.breadcrumb :items="[
        ['name' => 'Comparar', 'url' => route('comparar.index')],
        ['name' => $pa->nombre . ' vs ' . $pb->nombre, 'url' => url()->current()],
    ]" />

    <div class="max-w-4xl mx-auto">
        <nav class="text-sm text-gray-500 mb-4">
            <a href="{{ route('comparar.index') }}" class="hover:text-primary">Comparar</a>
            <span class="mx-1">/</span>
            <span class="text-gray-900">{{ $pa->nombre }} vs {{ $pb->nombre }}</span>
        </nav>

        <h1 class="font-serif text-2xl sm:text-3xl lg:text-4xl font-semibold text-gray-900 mb-6 tracking-tight leading-tight">
            {{ $pa->nombre }} <span class="text-gray-500">vs</span> {{ $pb->nombre }}
        </h1>

        {{-- Tabla comparativa --}}
        <div class="bg-white rounded-lg shadow overflow-hidden mb-8">
            <table class="w-full text-sm">
                <caption class="sr-only">Comparativa de contratación entre {{ $pa->nombre }} y {{ $pb->nombre }}</caption>
                <thead class="bg-gray-50 text-gray-600 uppercase text-xs">
                    <tr>
                        <th scope="col" class="text-left px-4 py-3"></th>
                        <th scope="col" class="text-right px-4 py-3">
                            <a href="{{ route('radiografia.show', \Illuminate\Support\Str::slug($pa->nombre)) }}" class="text-primary hover:underline">{{ $pa->nombre }}</a>
                        </th>
                        <th scope="col" class="text-right px-4 py-3">
                            <a href="{{ route('radiografia.show', \Illuminate\Support\Str::slug($pb->nombre)) }}" class="text-primary hover:underline">{{ $pb->nombre }}</a>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($metricas as [$label, $va, $vb, $tipo])
                    @php($aMayor = $va !== null && $vb !== null && $va > $vb)
                    @php($bMayor = $va !== null && $vb !== null && $vb > $va)
                    <tr>
                        <th scope="row" class="px-4 py-2.5 text-gray-600 font-normal text-left">{{ $label }}</th>
                        <td class="px-4 py-2.5 text-right {{ $aMayor ? 'font-bold text-gray-900' : 'text-gray-700' }}">{{ $fmt($va, $tipo) }}</td>
                        <td class="px-4 py-2.5 text-right {{ $bMayor ? 'font-bold text-gray-900' : 'text-gray-700' }}">{{ $fmt($vb, $tipo) }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- Top adjudicatarios lado a lado --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
            @foreach([[$pa, $da], [$pb, $db]] as [$prov, $datos])
            <div>
                <h2 class="text-sm font-semibold text-primary uppercase tracking-wide mb-3">Top adjudicatarios · {{ $prov->nombre }}</h2>
                <div class="bg-white rounded-lg shadow overflow-hidden">
                    <table class="w-full text-sm">
                        <caption class="sr-only">Top adjudicatarios de {{ $prov->nombre }}</caption>
                        <tbody class="divide-y divide-gray-100">
                            @forelse(array_slice($datos['top_adjudicatarios'], 0, 8) as $i => $row)
                            <tr>
                                <td class="px-3 py-2 text-gray-500">{{ $i + 1 }}</td>
                                <td class="px-3 py-2">
                                    <a href="{{ route('empresas.show', $row['nif']) }}" class="text-primary hover:underline">{{ \Illuminate\Support\Str::limit($row['nombre'], 32) }}</a>
                                </td>
                                <td class="px-3 py-2 text-right text-gray-600">{{ formatImporteCorto($row['total_importe']) }}</td>
                            </tr>
                            @empty
                            <tr><td class="px-3 py-2 text-gray-500">Sin datos</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            @endforeach
        </div>

        <p class="text-xs text-gray-500 mt-8">
            Solo se incluyen los contratos con código territorial (NUTS) a nivel provincial. Datos de fuentes
            oficiales (PLACSP y portales de datos abiertos). No es un sitio web oficial.
        </p>
    </div>

</x-layouts.app>
