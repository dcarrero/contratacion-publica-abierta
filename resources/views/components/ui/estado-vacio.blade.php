@props(['titulo'])
{{-- Estado sin datos. Ocupa el hueco del bloque que falta, pero con peso de nivel 2:
     no hay nada que destacar. --}}
<x-ui.tarjeta :padding="false" {{ $attributes->class('px-6 py-10 text-center') }}>
    <p class="font-semibold text-gray-900">{{ $titulo }}</p>
    @if($slot->isNotEmpty())
        <div class="mt-2 text-sm text-gray-500">{{ $slot }}</div>
    @endif
</x-ui.tarjeta>
