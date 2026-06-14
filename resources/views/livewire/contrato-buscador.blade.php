<div>
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900">Contratos públicos</h1>
        <p class="text-sm text-gray-500 mt-1">Buscador de contratos públicos de España</p>
    </div>

    {{-- Filtros --}}
    <div class="bg-white rounded-lg shadow p-4 sm:p-6 mb-6">
        {{-- Búsqueda --}}
        <div class="mb-4">
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
                    placeholder="Buscar por objeto, expediente o ID PLACSP..."
                    class="block w-full pl-10 pr-3 py-2.5 border border-gray-300 rounded-lg text-sm placeholder-gray-400 focus:ring-primary focus:border-primary"
                >
            </div>
        </div>

        {{-- Filtros geográficos --}}
        <div class="grid grid-cols-2 gap-3 mb-4">
            {{-- CCAA --}}
            <div>
                <label for="ccaa" class="block text-xs font-medium text-gray-500 mb-1">Comunidad Autónoma</label>
                <select wire:model.live="ccaa" id="ccaa" class="block w-full border-gray-300 rounded-md text-sm focus:ring-primary focus:border-primary">
                    <option value="">Todas</option>
                    @foreach($filterOptions['ccaa'] as $nuts => $nombre)
                        <option value="{{ $nuts }}">{{ $nombre }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Provincia --}}
            <div>
                <label for="provincia" class="block text-xs font-medium text-gray-500 mb-1">Provincia</label>
                <select wire:model.live="provincia" id="provincia" class="block w-full border-gray-300 rounded-md text-sm focus:ring-primary focus:border-primary" {{ $ccaa === '' ? 'disabled' : '' }}>
                    <option value="">Todas</option>
                    @if($ccaa !== '' && isset($filterOptions['provincias_por_ccaa'][$ccaa]))
                        @foreach($filterOptions['provincias_por_ccaa'][$ccaa] as $nutsP => $nombre)
                            <option value="{{ $nutsP }}">{{ $nombre }}</option>
                        @endforeach
                    @endif
                </select>
            </div>
        </div>

        {{-- Fila de filtros --}}
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
            {{-- Tipo contrato --}}
            <div>
                <label for="tipo_contrato" class="block text-xs font-medium text-gray-500 mb-1">Tipo</label>
                <select wire:model.live="tipo_contrato" id="tipo_contrato" class="block w-full border-gray-300 rounded-md text-sm focus:ring-primary focus:border-primary">
                    <option value="">Todos</option>
                    @foreach($filterOptions['tipos_contrato'] as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Procedimiento --}}
            <div>
                <label for="procedimiento" class="block text-xs font-medium text-gray-500 mb-1">Procedimiento</label>
                <select wire:model.live="procedimiento" id="procedimiento" class="block w-full border-gray-300 rounded-md text-sm focus:ring-primary focus:border-primary">
                    <option value="">Todos</option>
                    @foreach($filterOptions['procedimientos'] as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Estado --}}
            <div>
                <label for="estado" class="block text-xs font-medium text-gray-500 mb-1">Estado</label>
                <select wire:model.live="estado" id="estado" class="block w-full border-gray-300 rounded-md text-sm focus:ring-primary focus:border-primary">
                    <option value="">Todos</option>
                    @foreach($filterOptions['estados'] as $est)
                        <option value="{{ $est }}">{{ $est }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Año --}}
            <div>
                <label for="year" class="block text-xs font-medium text-gray-500 mb-1">Año</label>
                <select wire:model.live="year" id="year" class="block w-full border-gray-300 rounded-md text-sm focus:ring-primary focus:border-primary">
                    <option value="">Todos</option>
                    @foreach($filterOptions['years'] as $y)
                        <option value="{{ $y }}">{{ $y }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Importe mínimo --}}
            <div>
                <label for="importe_min" class="block text-xs font-medium text-gray-500 mb-1">Importe min.</label>
                <input
                    wire:model.live.debounce.500ms="importe_min"
                    type="number"
                    id="importe_min"
                    placeholder="0"
                    min="0"
                    step="1000"
                    class="block w-full border-gray-300 rounded-md text-sm focus:ring-primary focus:border-primary"
                >
            </div>

            {{-- Importe máximo --}}
            <div>
                <label for="importe_max" class="block text-xs font-medium text-gray-500 mb-1">Importe máx.</label>
                <input
                    wire:model.live.debounce.500ms="importe_max"
                    type="number"
                    id="importe_max"
                    placeholder="Sin límite"
                    min="0"
                    step="1000"
                    class="block w-full border-gray-300 rounded-md text-sm focus:ring-primary focus:border-primary"
                >
            </div>
        </div>

        {{-- Barra inferior: orden + limpiar --}}
        <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <label for="orden" class="text-xs font-medium text-gray-500">Ordenar:</label>
                <select wire:model.live="orden" id="orden" class="border-gray-300 rounded-md text-sm focus:ring-primary focus:border-primary">
                    <option value="fecha_desc">Más recientes</option>
                    <option value="fecha_asc">Más antiguos</option>
                    <option value="importe_desc">Mayor importe</option>
                    <option value="importe_asc">Menor importe</option>
                </select>
            </div>

            @if($this->hasActiveFilters())
                <button
                    wire:click="resetFilters"
                    class="text-sm text-primary hover:text-primary-dark font-medium"
                >
                    Limpiar filtros
                </button>
            @endif
        </div>
    </div>

    {{-- Resultados --}}
    <div class="bg-white rounded-lg shadow overflow-hidden">
        {{-- Contador + loading --}}
        <div class="px-4 py-3 border-b border-gray-100 flex items-center justify-between">
            <p class="text-sm text-gray-500">
                {{ number_format($contratos->total(), 0, ',', '.') }} contratos encontrados
            </p>
            <div wire:loading class="text-sm text-primary font-medium flex items-center gap-1">
                <svg class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
                Buscando...
            </div>
        </div>

        {{-- Tabla --}}
        <div class="overflow-x-auto" wire:loading.class="opacity-50">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-gray-600 uppercase text-xs">
                    <tr>
                        <th class="px-4 py-3 text-left">Fecha</th>
                        <th class="px-4 py-3 text-left">Objeto</th>
                        <th class="px-4 py-3 text-left hidden lg:table-cell">Organismo</th>
                        <th class="px-4 py-3 text-left hidden md:table-cell">Tipo</th>
                        <th class="px-4 py-3 text-right">Importe</th>
                        <th class="px-4 py-3 text-center hidden sm:table-cell">Estado</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($contratos as $contrato)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 text-gray-500 whitespace-nowrap text-xs">
                            {{ formatFecha($contrato->fecha_publicacion ?? $contrato->fecha_adjudicacion ?? $contrato->fecha_formalizacion) }}
                        </td>
                        <td class="px-4 py-3 text-gray-900">
                            <a href="{{ route('contratos.show', $contrato->placsp_id) }}" class="hover:text-primary hover:underline">
                                {{ Str::limit($contrato->objeto, 90) }}
                            </a>
                            @if($contrato->es_menor)
                                <span class="ml-1 inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-amber-100 text-amber-700">Menor</span>
                            @endif
                            <div class="text-xs text-gray-400 mt-0.5 lg:hidden">
                                {{ Str::limit($contrato->organismo?->nombre, 50) }}
                            </div>
                        </td>
                        <td class="px-4 py-3 text-gray-600 text-xs max-w-xs truncate hidden lg:table-cell">
                            {{ $contrato->organismo?->nombre ?? '—' }}
                        </td>
                        <td class="px-4 py-3 text-gray-500 text-xs whitespace-nowrap hidden md:table-cell">
                            {{ config('contratacion.tipos_contrato')[$contrato->tipo_contrato] ?? $contrato->tipo_contrato ?? '—' }}
                        </td>
                        <td class="px-4 py-3 text-right font-medium whitespace-nowrap">
                            {{ formatImporteCorto($contrato->importe_adjudicacion) }}
                        </td>
                        <td class="px-4 py-3 text-center hidden sm:table-cell">
                            @if($contrato->estado)
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium
                                    {{ $contrato->estado === 'Adjudicada' ? 'bg-green-100 text-green-700' : '' }}
                                    {{ $contrato->estado === 'Resuelta' ? 'bg-blue-100 text-blue-700' : '' }}
                                    {{ $contrato->estado === 'Evaluación' ? 'bg-yellow-100 text-yellow-700' : '' }}
                                    {{ $contrato->estado === 'Anulada' ? 'bg-red-100 text-red-700' : '' }}
                                    {{ !in_array($contrato->estado, ['Adjudicada', 'Resuelta', 'Evaluación', 'Anulada']) ? 'bg-gray-100 text-gray-600' : '' }}
                                ">
                                    {{ $contrato->estado }}
                                </span>
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="6" class="px-4 py-12 text-center text-gray-400">
                            <svg class="mx-auto h-10 w-10 text-gray-300 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.172 16.172a4 4 0 015.656 0M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                            </svg>
                            <p class="text-sm">No se encontraron contratos con los filtros aplicados.</p>
                            <button wire:click="resetFilters" class="mt-2 text-sm text-primary hover:underline">Limpiar filtros</button>
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Paginación --}}
    @if($contratos->hasPages())
        <div class="mt-6">
            {{ $contratos->links() }}
        </div>
    @endif
</div>
