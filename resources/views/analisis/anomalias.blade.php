<x-layouts.app title="Anomalías detectadas — Contratación Abierta">

    <h1 class="text-2xl font-bold text-gray-900 mb-2">Análisis de la contratación pública</h1>

    {{-- Sub-navegación --}}
    @include('analisis._subnav', ['active' => 'anomalias'])

    <div class="mb-6">
        <h2 class="text-xl font-semibold text-gray-900">Anomalías detectadas</h2>
        <p class="mt-1 text-sm text-gray-500">Detección automática de patrones sospechosos en contratación pública.</p>
    </div>

    {{-- KPIs --}}
    <section class="grid grid-cols-2 lg:grid-cols-3 gap-4 mb-8">
        <div class="bg-white rounded-lg shadow p-5">
            <p class="text-xs text-gray-500 uppercase tracking-wide">Total detectadas</p>
            <p class="text-2xl font-bold text-gray-900 mt-1">{{ number_format($stats['total'], 0, ',', '.') }}</p>
        </div>
        <div class="bg-white rounded-lg shadow p-5">
            <p class="text-xs text-gray-500 uppercase tracking-wide">Sin revisar</p>
            <p class="text-2xl font-bold text-amber-600 mt-1">{{ number_format($stats['no_revisadas'], 0, ',', '.') }}</p>
        </div>
        <div class="bg-white rounded-lg shadow p-5">
            <p class="text-xs text-gray-500 uppercase tracking-wide">Severidad alta</p>
            <p class="text-2xl font-bold text-red-600 mt-1">{{ number_format($stats['alta'], 0, ',', '.') }}</p>
        </div>
    </section>

    {{-- Filtros --}}
    <form method="GET" class="flex flex-wrap gap-3 mb-6">
        <select name="tipo" class="rounded-lg border-gray-300 text-sm" onchange="this.form.submit()">
            <option value="">Todos los tipos</option>
            <option value="fraccionamiento" @selected($filtro_tipo === 'fraccionamiento')>Fraccionamiento</option>
            <option value="concentracion" @selected($filtro_tipo === 'concentracion')>Concentración</option>
            <option value="pico_temporal" @selected($filtro_tipo === 'pico_temporal')>Pico temporal</option>
        </select>
        <select name="severidad" class="rounded-lg border-gray-300 text-sm" onchange="this.form.submit()">
            <option value="">Todas las severidades</option>
            <option value="alta" @selected($filtro_severidad === 'alta')>Alta</option>
            <option value="media" @selected($filtro_severidad === 'media')>Media</option>
            <option value="baja" @selected($filtro_severidad === 'baja')>Baja</option>
        </select>
    </form>

    {{-- Tabla --}}
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-gray-600 uppercase text-xs">
                    <tr>
                        <th class="px-4 py-3 text-left">Tipo</th>
                        <th class="px-4 py-3 text-left">Severidad</th>
                        <th class="px-4 py-3 text-left">Organismo</th>
                        <th class="px-4 py-3 text-left">Descripción</th>
                        <th class="px-4 py-3 text-left">Periodo</th>
                        <th class="px-4 py-3 text-left">Fecha</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($anomalias as $anomalia)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3">
                            @php
                                $badgeColor = match($anomalia->tipo) {
                                    'fraccionamiento' => 'bg-orange-100 text-orange-700',
                                    'concentracion' => 'bg-purple-100 text-purple-700',
                                    'pico_temporal' => 'bg-blue-100 text-blue-700',
                                    default => 'bg-gray-100 text-gray-700',
                                };
                                $tipoLabel = match($anomalia->tipo) {
                                    'fraccionamiento' => 'Fraccionamiento',
                                    'concentracion' => 'Concentración',
                                    'pico_temporal' => 'Pico temporal',
                                    default => $anomalia->tipo,
                                };
                            @endphp
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium {{ $badgeColor }}">
                                {{ $tipoLabel }}
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            @php
                                $sevColor = match($anomalia->severidad) {
                                    'alta' => 'bg-red-100 text-red-700',
                                    'media' => 'bg-amber-100 text-amber-700',
                                    'baja' => 'bg-green-100 text-green-700',
                                    default => 'bg-gray-100 text-gray-700',
                                };
                            @endphp
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium {{ $sevColor }}">
                                {{ ucfirst($anomalia->severidad) }}
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            @if($anomalia->organismo)
                                <a href="{{ route('organismos.show', $anomalia->organismo->nif) }}" class="text-gray-900 hover:text-primary hover:underline">
                                    {{ Str::limit($anomalia->organismo->nombre, 40) }}
                                </a>
                            @else
                                <span class="text-gray-400">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-gray-600 text-xs max-w-md">
                            {{ Str::limit($anomalia->descripcion, 120) }}
                        </td>
                        <td class="px-4 py-3 text-gray-500 text-xs whitespace-nowrap">{{ $anomalia->periodo }}</td>
                        <td class="px-4 py-3 text-gray-500 text-xs whitespace-nowrap">{{ $anomalia->created_at->format('d/m/Y') }}</td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="6" class="px-4 py-8 text-center text-gray-500">
                            No se han detectado anomalías todavía. Ejecuta <code class="bg-gray-100 px-1 rounded">php artisan anomalias:detectar</code> para iniciar el análisis.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-6 text-xs text-gray-400">
        <p>Las anomalías se detectan automáticamente cada lunes. Los resultados son indicativos y requieren verificación humana.</p>
    </div>

</x-layouts.app>
