<x-layouts.app title="{{ $comunidad->nombre }} — Contratación Abierta">
    <div class="mb-6">
        <div class="flex items-center gap-2 text-sm text-gray-500 mb-2">
            <a href="{{ route('administraciones.index') }}" class="hover:text-primary hover:underline">Administraciones</a>
            <span>/</span>
            <span>{{ $comunidad->nombre }}</span>
        </div>
        <h1 class="text-2xl font-bold text-gray-900">{{ $comunidad->nombre }}</h1>
        <p class="text-sm text-gray-500 mt-1">Código NUTS: {{ $comunidad->nuts }}</p>
    </div>

    {{-- KPIs --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
        <div class="bg-white rounded-lg shadow p-4">
            <p class="text-xs font-medium text-gray-500 uppercase">Contratos</p>
            <p class="text-2xl font-bold text-gray-900 mt-1">{{ number_format($total_contratos, 0, ',', '.') }}</p>
        </div>
        <div class="bg-white rounded-lg shadow p-4">
            <p class="text-xs font-medium text-gray-500 uppercase">Importe total</p>
            <p class="text-2xl font-bold text-gray-900 mt-1">{{ formatImporteCorto($total_importe) }}</p>
        </div>
        <div class="bg-white rounded-lg shadow p-4">
            <p class="text-xs font-medium text-gray-500 uppercase">Organismos</p>
            <p class="text-2xl font-bold text-gray-900 mt-1">{{ number_format($total_organismos, 0, ',', '.') }}</p>
        </div>
        <div class="bg-white rounded-lg shadow p-4">
            <p class="text-xs font-medium text-gray-500 uppercase">Adjudicatarios</p>
            <p class="text-2xl font-bold text-gray-900 mt-1">{{ number_format($total_adjudicatarios, 0, ',', '.') }}</p>
        </div>
    </div>

    {{-- Per cápita --}}
    @if($comunidad->poblacion)
    <div class="grid grid-cols-2 lg:grid-cols-3 gap-4 mb-8">
        <div class="bg-blue-50 rounded-lg shadow p-4">
            <p class="text-xs font-medium text-blue-600 uppercase">Población (INE 2024)</p>
            <p class="text-xl font-bold text-gray-900 mt-1">{{ number_format($comunidad->poblacion, 0, ',', '.') }}</p>
        </div>
        <div class="bg-blue-50 rounded-lg shadow p-4">
            <p class="text-xs font-medium text-blue-600 uppercase">Gasto per cápita</p>
            <p class="text-xl font-bold text-gray-900 mt-1">
                @if($comunidad->poblacion > 0)
                    {{ formatImporte($total_importe / $comunidad->poblacion) }}/hab
                @else
                    —
                @endif
            </p>
        </div>
        <div class="bg-blue-50 rounded-lg shadow p-4">
            <p class="text-xs font-medium text-blue-600 uppercase">Contratos per cápita</p>
            <p class="text-xl font-bold text-gray-900 mt-1">
                {{ number_format($total_contratos / $comunidad->poblacion, 2, ',', '.') }}
            </p>
        </div>
    </div>
    @endif

    <div class="grid lg:grid-cols-2 gap-6">
        {{-- Top organismos --}}
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <div class="px-4 py-3 border-b border-gray-100">
                <h2 class="font-semibold text-gray-900">Top organismos</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-gray-600 uppercase text-xs">
                        <tr>
                            <th class="px-4 py-2 text-left">Nombre</th>
                            <th class="px-4 py-2 text-right">Contratos</th>
                            <th class="px-4 py-2 text-right">Importe</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($top_organismos as $org)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-2">
                                <a href="{{ route('organismos.show', $org->nif) }}" class="text-gray-900 hover:text-primary hover:underline">
                                    {{ Str::limit($org->nombre, 50) }}
                                </a>
                            </td>
                            <td class="px-4 py-2 text-right text-gray-500">{{ number_format($org->total_contratos, 0, ',', '.') }}</td>
                            <td class="px-4 py-2 text-right font-medium whitespace-nowrap">{{ formatImporteCorto($org->total_importe) }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="px-4 py-3 border-t border-gray-100">
                <a href="{{ route('organismos.index', ['ccaa' => $comunidad->nuts]) }}" class="text-sm text-primary hover:underline">
                    Ver todos los organismos →
                </a>
            </div>
        </div>

        {{-- Últimos contratos --}}
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <div class="px-4 py-3 border-b border-gray-100">
                <h2 class="font-semibold text-gray-900">Últimos contratos</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-gray-600 uppercase text-xs">
                        <tr>
                            <th class="px-4 py-2 text-left">Fecha</th>
                            <th class="px-4 py-2 text-left">Objeto</th>
                            <th class="px-4 py-2 text-right">Importe</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($ultimos_contratos as $contrato)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-2 text-gray-500 text-xs whitespace-nowrap">{{ formatFecha($contrato->fecha_publicacion ?? $contrato->fecha_adjudicacion ?? $contrato->fecha_formalizacion) }}</td>
                            <td class="px-4 py-2">
                                <a href="{{ route('contratos.show', $contrato->placsp_id) }}" class="text-gray-900 hover:text-primary hover:underline">
                                    {{ Str::limit($contrato->objeto, 60) }}
                                </a>
                            </td>
                            <td class="px-4 py-2 text-right font-medium whitespace-nowrap">{{ formatImporteCorto($contrato->importe_adjudicacion) }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="px-4 py-3 border-t border-gray-100">
                <a href="{{ route('contratos.index', ['ccaa' => $comunidad->nuts]) }}" class="text-sm text-primary hover:underline">
                    Ver todos los contratos →
                </a>
            </div>
        </div>
    </div>
</x-layouts.app>
