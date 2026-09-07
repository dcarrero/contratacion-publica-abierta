@props(['etiqueta'])
{{-- Dato suelto de nivel 2: misma anatomía que un KPI (etiqueta arriba, cifra debajo)
     pero sin la presencia del bloque principal. --}}
<x-ui.tarjeta :padding="false" {{ $attributes->class('p-5') }} data-cifras>
    <p class="text-xs uppercase tracking-wide text-gray-500">{{ $etiqueta }}</p>
    <div class="mt-1">{{ $slot }}</div>
</x-ui.tarjeta>
