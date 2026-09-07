@props([
    'titulo',
    'descripcion' => null,
    'nivel' => 2,       // nivel del encabezado: 2 en la página, 3 dentro de un bloque
])
{{-- Nivel 2 de la jerarquía: sección de apoyo. El título pesa menos que el del bloque
     principal y la separación entre secciones se consigue con aire (space-y del
     contenedor), no con bordes ni sombras añadidas. --}}
<section {{ $attributes }}>
    <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
        <h{{ $nivel }} class="text-base font-semibold text-gray-900">{{ $titulo }}</h{{ $nivel }}>
        @isset($accion)
            <div class="text-sm">{{ $accion }}</div>
        @endisset
    </div>

    @if($descripcion)
        <p class="mt-1 max-w-2xl text-sm text-gray-500">{{ $descripcion }}</p>
    @endif

    <div class="mt-4">{{ $slot }}</div>
</section>
