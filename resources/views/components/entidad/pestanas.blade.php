@props([
    'activa',       // 'ficha' | 'historico' | 'red'
    'urlFicha',
    'urlHistorico',
    'urlRed' => null,
    'etiquetaFicha' => 'Ficha',
])
{{-- Navegación entre las vistas de una entidad. Cada una responde a una pregunta distinta:
     qué pasa ahora, qué ha pasado siempre, y con quién se relaciona. --}}
<nav aria-label="Secciones de la entidad" class="border-b border-gray-200 mb-6">
    <ul class="flex flex-wrap -mb-px text-sm font-medium">
        @foreach(array_filter([
            'ficha' => ['url' => $urlFicha, 'texto' => $etiquetaFicha],
            'historico' => ['url' => $urlHistorico, 'texto' => 'Histórico'],
            'red' => $urlRed ? ['url' => $urlRed, 'texto' => 'Red de relaciones'] : null,
        ]) as $clave => $item)
            <li>
                <a href="{{ $item['url'] }}"
                   @if($clave === $activa) aria-current="page" @endif
                   @class([
                       'inline-block px-4 py-2.5 border-b-2 transition',
                       'border-primary text-primary font-semibold' => $clave === $activa,
                       'border-transparent text-gray-500 hover:text-gray-800 hover:border-gray-300' => $clave !== $activa,
                   ])>{{ $item['texto'] }}</a>
            </li>
        @endforeach
    </ul>
</nav>
