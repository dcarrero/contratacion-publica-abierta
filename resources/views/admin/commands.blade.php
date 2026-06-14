<x-layouts.admin :title="'Comandos'">
    @php $adminActive = 'commands'; @endphp

    <h1 class="text-2xl font-bold text-gray-800 mb-6">Comandos Artisan</h1>

    @if($errors->any())
        <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-4 text-sm">
            {{ $errors->first() }}
        </div>
    @endif

    <div class="grid lg:grid-cols-2 gap-6">
        {{-- Lista de comandos --}}
        <div class="bg-white rounded-lg shadow-sm border p-5">
            <h2 class="text-lg font-semibold text-gray-800 mb-4">Comandos disponibles</h2>
            <div class="space-y-2">
                @foreach($commands as $command)
                    <form method="POST" action="{{ route('admin.commands.run') }}" class="flex items-center gap-3">
                        @csrf
                        <input type="hidden" name="command" value="{{ $command }}">
                        <code class="flex-1 text-sm bg-gray-100 px-3 py-2 rounded font-mono">{{ $command }}</code>
                        <button type="submit"
                                class="bg-gray-900 text-white px-3 py-2 rounded-lg text-xs font-medium hover:bg-gray-800 transition whitespace-nowrap"
                                onclick="return confirm('¿Ejecutar {{ $command }}?')">
                            Ejecutar
                        </button>
                    </form>
                @endforeach
            </div>
        </div>

        {{-- Output --}}
        <div class="bg-white rounded-lg shadow-sm border p-5">
            <h2 class="text-lg font-semibold text-gray-800 mb-4">Salida</h2>
            @if($output !== null)
                <div class="mb-2">
                    <span class="text-xs text-gray-500">Comando ejecutado:</span>
                    <code class="text-sm font-mono text-blue-600">{{ $executedCommand }}</code>
                </div>
                <pre class="bg-gray-900 text-green-400 p-4 rounded-lg text-xs font-mono overflow-x-auto max-h-96 whitespace-pre-wrap">{{ $output ?: '(sin salida)' }}</pre>
            @else
                <p class="text-gray-400 text-sm">Selecciona un comando para ejecutar.</p>
            @endif
        </div>
    </div>
</x-layouts.admin>
