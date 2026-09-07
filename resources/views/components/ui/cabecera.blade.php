@props(['titulo'])
{{-- Cabecera de página: el título es el único elemento de su tamaño en toda la vista, y
     las acciones (seguir, descargar) se apartan a la derecha para no disputarle el sitio
     sin salir de su bloque. --}}
<header {{ $attributes->class('mb-8') }}>
    <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
        <div class="min-w-0">
            <h1 class="font-serif text-2xl font-semibold leading-[1.15] tracking-tight text-gray-900 sm:text-3xl lg:text-4xl">{{ $titulo }}</h1>
            @isset($meta)
                <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-2 text-sm text-gray-500">{{ $meta }}</div>
            @endisset
        </div>
        @isset($acciones)
            <div class="shrink-0">{{ $acciones }}</div>
        @endisset
    </div>
</header>
