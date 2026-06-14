<div>
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900">Organismos contratantes</h1>
        <p class="text-sm text-gray-500 mt-1">Ranking de organismos públicos contratantes</p>
    </div>

    {{-- Filtros --}}
    <div class="bg-white rounded-lg shadow p-4 sm:p-6 mb-6">
        <div class="flex flex-col sm:flex-row gap-3">
            <div class="flex-1">
                <label for="busqueda" class="sr-only">Buscar</label>
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                        <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                        </svg>
                    </div>
                    <input
                        wire:model.live.debounce.300ms="busqueda"
                        type="text"
                        id="busqueda"
                        placeholder="Buscar por nombre o NIF..."
                        class="block w-full pl-10 pr-3 py-2.5 border border-gray-300 rounded-lg text-sm placeholder-gray-400 focus:ring-primary focus:border-primary"
                    >
                </div>
            </div>
            <div class="flex items-center gap-3">
                <div class="flex items-center gap-2">
                    <label for="ccaa" class="text-xs font-medium text-gray-500 whitespace-nowrap">CCAA:</label>
                    <select wire:model.live="ccaa" id="ccaa" class="border-gray-300 rounded-md text-sm focus:ring-primary focus:border-primary">
                        <option value="">Todas</option>
                        @foreach($ccaaOptions as $nuts => $nombre)
                            <option value="{{ $nuts }}">{{ $nombre }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex items-center gap-2">
                    <label for="orden" class="text-xs font-medium text-gray-500 whitespace-nowrap">Ordenar:</label>
                    <select wire:model.live="orden" id="orden" class="border-gray-300 rounded-md text-sm focus:ring-primary focus:border-primary">
                        <option value="importe_desc">Mayor importe</option>
                        <option value="importe_asc">Menor importe</option>
                        <option value="contratos_desc">Más contratos</option>
                        <option value="contratos_asc">Menos contratos</option>
                        <option value="nombre_asc">Nombre A-Z</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    {{-- Tabla --}}
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="px-4 py-3 border-b border-gray-100 flex items-center justify-between">
            <p class="text-sm text-gray-500">{{ number_format($organismos->total(), 0, ',', '.') }} organismos</p>
            <div wire:loading class="text-sm text-primary font-medium flex items-center gap-1">
                <svg class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
                Buscando...
            </div>
        </div>

        <div class="overflow-x-auto" wire:loading.class="opacity-50">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-gray-600 uppercase text-xs">
                    <tr>
                        <th class="px-4 py-3 text-left w-12">#</th>
                        <th class="px-4 py-3 text-left">Nombre</th>
                        <th class="px-4 py-3 text-left hidden sm:table-cell">NIF</th>
                        <th class="px-4 py-3 text-right">Contratos</th>
                        <th class="px-4 py-3 text-right">Importe total</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($organismos as $i => $org)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 text-gray-400 text-xs">{{ $organismos->firstItem() + $i }}</td>
                        <td class="px-4 py-3">
                            <a href="{{ route('organismos.show', $org->nif) }}" class="font-medium text-gray-900 hover:text-primary hover:underline">
                                {{ $org->nombre }}
                            </a>
                            <span class="sm:hidden block text-xs text-gray-400 font-mono mt-0.5">{{ $org->nif }}</span>
                        </td>
                        <td class="px-4 py-3 text-gray-500 font-mono text-xs hidden sm:table-cell">{{ $org->nif }}</td>
                        <td class="px-4 py-3 text-right">{{ number_format($org->total_contratos, 0, ',', '.') }}</td>
                        <td class="px-4 py-3 text-right font-medium whitespace-nowrap">{{ formatImporteCorto($org->total_importe) }}</td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="5" class="px-4 py-12 text-center text-gray-400">
                            No se encontraron organismos.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if($organismos->hasPages())
        <div class="mt-6">
            {{ $organismos->links() }}
        </div>
    @endif
</div>
