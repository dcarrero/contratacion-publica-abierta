<x-layouts.admin :title="'Dashboard'">
    @php $adminActive = 'dashboard'; @endphp

    <h1 class="text-2xl font-bold text-gray-800 mb-6">Dashboard</h1>

    {{-- KPIs --}}
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4 mb-8">
        @foreach([
            ['label' => 'Contratos', 'value' => number_format($kpis['contratos'], 0, ',', '.'), 'color' => 'blue'],
            ['label' => 'Organismos', 'value' => number_format($kpis['organismos'], 0, ',', '.'), 'color' => 'green'],
            ['label' => 'Adjudicatarios', 'value' => number_format($kpis['adjudicatarios'], 0, ',', '.'), 'color' => 'purple'],
            ['label' => 'Fuentes activas', 'value' => $kpis['fuentes'], 'color' => 'teal'],
            ['label' => 'Import logs', 'value' => number_format($kpis['import_logs'], 0, ',', '.'), 'color' => 'gray'],
            ['label' => 'Anomalías', 'value' => number_format($kpis['anomalias'], 0, ',', '.'), 'color' => 'red'],
            ['label' => 'Suscripciones', 'value' => $kpis['suscripciones'], 'color' => 'yellow'],
        ] as $kpi)
            <div class="bg-white rounded-lg shadow-sm border p-4">
                <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">{{ $kpi['label'] }}</p>
                <p class="text-2xl font-bold text-{{ $kpi['color'] }}-600 mt-1">{{ $kpi['value'] }}</p>
            </div>
        @endforeach

        <div class="bg-white rounded-lg shadow-sm border p-4">
            <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Tamaño BD</p>
            <p class="text-2xl font-bold text-gray-700 mt-1">
                @if($dbSize > 1073741824)
                    {{ number_format($dbSize / 1073741824, 1, ',', '.') }} GB
                @else
                    {{ number_format($dbSize / 1048576, 0, ',', '.') }} MB
                @endif
            </p>
        </div>
    </div>

    <div class="grid lg:grid-cols-2 gap-6">
        {{-- Sistema --}}
        <div class="bg-white rounded-lg shadow-sm border p-5">
            <h2 class="text-lg font-semibold text-gray-800 mb-3">Sistema</h2>
            <dl class="space-y-2 text-sm">
                <div class="flex justify-between">
                    <dt class="text-gray-500">Espacio libre en disco</dt>
                    <dd class="font-medium">{{ number_format($diskFree / 1073741824, 1, ',', '.') }} GB</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500">Última actualización stats</dt>
                    <dd class="font-medium">
                        @if($lastStats)
                            {{ date('d/m/Y H:i', $lastStats) }}
                        @else
                            <span class="text-red-500">Nunca</span>
                        @endif
                    </dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500">PHP</dt>
                    <dd class="font-medium">{{ PHP_VERSION }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500">Laravel</dt>
                    <dd class="font-medium">{{ app()->version() }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500">BD driver</dt>
                    <dd class="font-medium">{{ config('database.default') }}</dd>
                </div>
            </dl>
        </div>

        {{-- Semáforo fuentes --}}
        <div class="bg-white rounded-lg shadow-sm border p-5">
            <h2 class="text-lg font-semibold text-gray-800 mb-3">Estado fuentes</h2>
            <div class="space-y-2">
                @foreach($fuentes as $fuente)
                    <div class="flex items-center justify-between text-sm">
                        <div class="flex items-center gap-2">
                            <span class="w-3 h-3 rounded-full
                                {{ match($fuente->semaforo) {
                                    'verde' => 'bg-green-500',
                                    'amarillo' => 'bg-yellow-400',
                                    'rojo' => 'bg-red-500',
                                    default => 'bg-gray-300',
                                } }}"></span>
                            <span class="text-gray-700">{{ $fuente->nombre }}</span>
                        </div>
                        <span class="text-gray-400 text-xs">
                            {{ $fuente->ultima_sincronizacion?->diffForHumans() ?? 'Nunca' }}
                        </span>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- Errores recientes --}}
    @if($erroresRecientes->isNotEmpty())
        <div class="mt-6 bg-white rounded-lg shadow-sm border p-5">
            <h2 class="text-lg font-semibold text-gray-800 mb-3">Errores recientes en importaciones</h2>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b text-left text-gray-500">
                            <th class="pb-2 pr-4">Fecha</th>
                            <th class="pb-2 pr-4">Fuente</th>
                            <th class="pb-2 pr-4">Tipo</th>
                            <th class="pb-2 pr-4 text-right">Errores</th>
                            <th class="pb-2">Notas</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        @foreach($erroresRecientes as $log)
                            <tr>
                                <td class="py-2 pr-4 text-gray-500 whitespace-nowrap">{{ $log->created_at->format('d/m H:i') }}</td>
                                <td class="py-2 pr-4">{{ $log->fuenteDatos?->nombre ?? '—' }}</td>
                                <td class="py-2 pr-4">{{ $log->tipo }}</td>
                                <td class="py-2 pr-4 text-right text-red-600 font-medium">{{ number_format($log->errores, 0, ',', '.') }}</td>
                                <td class="py-2 text-gray-500 truncate max-w-xs">{{ Str::limit($log->notas, 80) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</x-layouts.admin>
