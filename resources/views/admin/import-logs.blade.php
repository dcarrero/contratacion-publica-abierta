<x-layouts.admin :title="'Import Logs'">
    @php $adminActive = 'import-logs'; @endphp

    <h1 class="text-2xl font-bold text-gray-800 mb-6">Import Logs</h1>

    {{-- Filtros --}}
    <form method="GET" class="bg-white rounded-lg shadow-sm border p-4 mb-6">
        <div class="flex flex-wrap gap-4 items-end">
            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Fuente</label>
                <select name="fuente" class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm">
                    <option value="">Todas</option>
                    @foreach($fuentes as $fuente)
                        <option value="{{ $fuente->id }}" {{ request('fuente') == $fuente->id ? 'selected' : '' }}>
                            {{ $fuente->nombre }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Tipo</label>
                <select name="tipo" class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm">
                    <option value="">Todos</option>
                    @foreach($tipos as $tipo)
                        <option value="{{ $tipo }}" {{ request('tipo') == $tipo ? 'selected' : '' }}>
                            {{ $tipo }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="flex items-center gap-2 text-sm text-gray-600">
                    <input type="checkbox" name="solo_errores" value="1" {{ request('solo_errores') ? 'checked' : '' }}
                           class="rounded border-gray-300">
                    Solo con errores
                </label>
            </div>
            <button type="submit" class="bg-gray-900 text-white px-4 py-1.5 rounded-lg text-sm hover:bg-gray-800 transition">
                Filtrar
            </button>
            @if(request()->hasAny(['fuente', 'tipo', 'solo_errores']))
                <a href="{{ route('admin.import-logs') }}" class="text-sm text-gray-500 hover:text-gray-700">Limpiar</a>
            @endif
        </div>
    </form>

    {{-- Tabla --}}
    <div class="bg-white rounded-lg shadow-sm border overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b bg-gray-50 text-left text-gray-500 text-xs uppercase tracking-wide">
                    <th class="px-4 py-3">Fecha</th>
                    <th class="px-4 py-3">Fuente</th>
                    <th class="px-4 py-3">Tipo</th>
                    <th class="px-4 py-3 text-right">Procesados</th>
                    <th class="px-4 py-3 text-right">Nuevos</th>
                    <th class="px-4 py-3 text-right">Actualizados</th>
                    <th class="px-4 py-3 text-right">Errores</th>
                    <th class="px-4 py-3 text-right">Duración</th>
                    <th class="px-4 py-3">Notas</th>
                </tr>
            </thead>
            <tbody class="divide-y">
                @forelse($logs as $log)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-2.5 whitespace-nowrap text-gray-500">{{ $log->created_at->format('d/m/Y H:i') }}</td>
                        <td class="px-4 py-2.5">{{ $log->fuenteDatos?->nombre ?? '—' }}</td>
                        <td class="px-4 py-2.5">{{ $log->tipo }}</td>
                        <td class="px-4 py-2.5 text-right">{{ number_format($log->procesados, 0, ',', '.') }}</td>
                        <td class="px-4 py-2.5 text-right text-green-600">{{ number_format($log->nuevos, 0, ',', '.') }}</td>
                        <td class="px-4 py-2.5 text-right text-blue-600">{{ number_format($log->actualizados, 0, ',', '.') }}</td>
                        <td class="px-4 py-2.5 text-right {{ $log->errores > 0 ? 'text-red-600 font-medium' : 'text-gray-400' }}">
                            {{ number_format($log->errores, 0, ',', '.') }}
                        </td>
                        <td class="px-4 py-2.5 text-right text-gray-500 whitespace-nowrap">
                            @if($log->duracion_segundos)
                                {{ gmdate($log->duracion_segundos >= 3600 ? 'H:i:s' : 'i:s', (int) $log->duracion_segundos) }}
                            @else
                                —
                            @endif
                        </td>
                        <td class="px-4 py-2.5 text-gray-500 truncate max-w-xs" title="{{ $log->notas }}">
                            {{ Str::limit($log->notas, 60) }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="px-4 py-8 text-center text-gray-400">Sin registros</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $logs->links() }}
    </div>
</x-layouts.admin>
