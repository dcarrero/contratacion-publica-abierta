@props([
    'destacado' => false,
    'kpiCols' => 'grid-cols-2 lg:grid-cols-4',
])
{{-- Nivel 1 de la jerarquía: el bloque que responde a la pregunta principal de la página
     (qué está pasando este año). Es la única superficie elevada; todo lo demás usa
     tarjetas planas. Las bandas internas se separan con filetes, no con más cajas.
     La rejilla de KPIs usa gap-px sobre fondo gris para dibujar la retícula sin bordes
     que se dupliquen al hacer wrap. --}}
<div {{ $attributes->class([
    'overflow-hidden rounded-xl bg-white',
    'shadow-lg shadow-gray-900/5 ring-1 ring-gray-900/5' => $destacado,
    'ring-1 ring-gray-200' => ! $destacado,
]) }}>
    @isset($cabecera)
        <div class="px-5 pt-5 pb-4 sm:px-6 sm:pt-6">{{ $cabecera }}</div>
    @endisset

    @isset($kpis)
        <div class="grid {{ $kpiCols }} gap-px border-y border-gray-100 bg-gray-100">{{ $kpis }}</div>
    @endisset

    @if($slot->isNotEmpty())
        <div class="px-5 py-5 sm:px-6">{{ $slot }}</div>
    @endif
</div>
