<x-layouts.admin :title="'Documentación'">
    @php $adminActive = 'docs'; @endphp

    <h1 class="text-2xl font-bold text-gray-800 mb-6">Documentación</h1>

    <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
        @foreach($pages as $slug => $label)
            @if($slug !== 'index')
                <a href="{{ route('admin.docs', $slug) }}"
                   class="bg-white rounded-lg shadow-sm border p-5 hover:shadow-md transition group">
                    <h2 class="font-semibold text-gray-800 group-hover:text-blue-600 transition">{{ $label }}</h2>
                    <p class="text-sm text-gray-500 mt-1">
                        @switch($slug)
                            @case('comandos')
                                Guía completa de todos los comandos Artisan disponibles.
                                @break
                            @case('arquitectura')
                                Estructura del proyecto, flujo de datos y decisiones técnicas.
                                @break
                            @case('fuentes')
                                Descripción de cada fuente de datos, formatos y URLs.
                                @break
                        @endswitch
                    </p>
                </a>
            @endif
        @endforeach
    </div>
</x-layouts.admin>
