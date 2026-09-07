@props([
    'anios',            // años ofrecidos, del más reciente al más antiguo
    'anio',             // año mostrado
    'anioReferencia',   // año que muestra la ficha por defecto (enlaza a la URL canónica)
    'rutaFicha',
    'rutaAnio',
    'param',
    'urlHistorico',
])
<div class="flex flex-wrap items-center gap-1 text-xs">
    @foreach($anios as $opcion)
        <a href="{{ $opcion === $anioReferencia ? route($rutaFicha, $param) : route($rutaAnio, [$param, $opcion]) }}"
           @if($opcion === $anio) aria-current="page" @endif
           @class([
               'rounded-lg px-3 py-1.5 font-medium transition',
               'bg-primary text-white' => $opcion === $anio,
               'text-gray-600 hover:bg-gray-100' => $opcion !== $anio,
           ])>{{ $opcion }}</a>
    @endforeach
    <a href="{{ $urlHistorico }}" class="rounded-lg px-3 py-1.5 font-medium text-primary transition hover:bg-red-50">
        Todos los años →
    </a>
</div>
