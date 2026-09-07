@props(['padding' => true])
{{-- Nivel 2: superficie de apoyo (tablas, distribuciones, datos secundarios). Plana,
     delimitada con un filete en vez de sombra, para que no compita con el panel principal. --}}
<div {{ $attributes->class([
    'overflow-hidden rounded-lg bg-white ring-1 ring-gray-200',
    'p-5 sm:p-6' => $padding,
]) }}>{{ $slot }}</div>
