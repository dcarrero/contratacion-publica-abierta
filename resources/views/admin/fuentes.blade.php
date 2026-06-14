<x-layouts.admin :title="'Fuentes de datos'">
    @php $adminActive = 'fuentes'; @endphp

    <h1 class="text-2xl font-bold text-gray-800 mb-6">Fuentes de datos</h1>

    <div class="grid gap-4">
        @foreach($fuentes as $fuente)
            <div class="bg-white rounded-lg shadow-sm border p-5">
                <div class="flex items-start justify-between">
                    <div class="flex items-center gap-3">
                        <span class="w-3 h-3 rounded-full flex-shrink-0
                            {{ match($fuente->semaforo) {
                                'verde' => 'bg-green-500',
                                'amarillo' => 'bg-yellow-400',
                                'rojo' => 'bg-red-500',
                                default => 'bg-gray-300',
                            } }}"></span>
                        <div>
                            <h2 class="font-semibold text-gray-800">{{ $fuente->nombre }}</h2>
                            <p class="text-xs text-gray-400 mt-0.5">{{ $fuente->slug }}</p>
                        </div>
                    </div>
                    <span class="text-xs px-2 py-1 rounded-full {{ $fuente->activo ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' }}">
                        {{ $fuente->activo ? 'Activa' : 'Inactiva' }}
                    </span>
                </div>

                <div class="mt-4 grid sm:grid-cols-2 lg:grid-cols-4 gap-4 text-sm">
                    <div>
                        <span class="text-gray-500">Contratos</span>
                        <p class="font-medium">{{ number_format($fuente->total_contratos, 0, ',', '.') }}</p>
                    </div>
                    <div>
                        <span class="text-gray-500">Tipo</span>
                        <p class="font-medium">{{ $fuente->tipo ?? '—' }}</p>
                    </div>
                    <div>
                        <span class="text-gray-500">Frecuencia</span>
                        <p class="font-medium">{{ $fuente->frecuencia ?? '—' }}</p>
                    </div>
                    <div>
                        <span class="text-gray-500">Última sincronización</span>
                        <p class="font-medium">
                            {{ $fuente->ultima_sincronizacion?->format('d/m/Y H:i') ?? 'Nunca' }}
                        </p>
                    </div>
                </div>

                @if($fuente->url)
                    <p class="mt-3 text-xs text-gray-400 truncate">
                        <a href="{{ $fuente->url }}" target="_blank" class="hover:text-gray-600">{{ $fuente->url }}</a>
                    </p>
                @endif

                @if($fuente->ultimo_log)
                    <div class="mt-3 border-t pt-3 text-xs text-gray-500">
                        Último import: {{ $fuente->ultimo_log->created_at->format('d/m/Y H:i') }}
                        — {{ number_format($fuente->ultimo_log->procesados, 0, ',', '.') }} procesados,
                        {{ number_format($fuente->ultimo_log->nuevos, 0, ',', '.') }} nuevos,
                        {{ number_format($fuente->ultimo_log->errores, 0, ',', '.') }} errores
                        @if($fuente->ultimo_log->duracion_segundos)
                            ({{ gmdate('i:s', (int) $fuente->ultimo_log->duracion_segundos) }})
                        @endif
                    </div>
                @endif
            </div>
        @endforeach
    </div>
</x-layouts.admin>
