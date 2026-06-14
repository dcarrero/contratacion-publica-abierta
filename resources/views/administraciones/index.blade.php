<x-layouts.app title="Administraciones — Contratación Abierta">
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900">Administraciones</h1>
        <p class="text-sm text-gray-500 mt-1">Comunidades autónomas y ciudades autónomas de España</p>
    </div>

    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-gray-600 uppercase text-xs">
                    <tr>
                        <th class="px-4 py-3 text-left">Comunidad Autónoma</th>
                        <th class="px-4 py-3 text-right">Organismos</th>
                        <th class="px-4 py-3 text-right">Contratos</th>
                        <th class="px-4 py-3 text-right">Importe total</th>
                        <th class="px-4 py-3 text-right">Per cápita</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($ccaa as $ca)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3">
                            <a href="{{ route('administraciones.show', $ca->nuts) }}" class="font-medium text-gray-900 hover:text-primary hover:underline">
                                {{ $ca->nombre }}
                            </a>
                            <span class="text-xs text-gray-400 ml-1">{{ $ca->nuts }}</span>
                        </td>
                        <td class="px-4 py-3 text-right">{{ number_format($ca->stats_organismos, 0, ',', '.') }}</td>
                        <td class="px-4 py-3 text-right">{{ number_format($ca->stats_contratos, 0, ',', '.') }}</td>
                        <td class="px-4 py-3 text-right font-medium whitespace-nowrap">{{ formatImporteCorto($ca->stats_importe) }}</td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            @if($ca->stats_per_capita !== null)
                                {{ formatImporteCorto($ca->stats_per_capita) }}/hab
                            @else
                                <span class="text-gray-300">—</span>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</x-layouts.app>
