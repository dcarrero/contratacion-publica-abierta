@php
    $tabs = [
        ['route' => 'analisis', 'label' => 'Gráficas', 'key' => 'graficas'],
        ['route' => 'rankings', 'label' => 'Rankings', 'key' => 'rankings'],
        ['route' => 'anomalias.index', 'label' => 'Anomalías', 'key' => 'anomalias'],
        ['route' => 'grafo', 'label' => 'Red de relaciones', 'key' => 'grafo'],
        ['route' => 'informes.index', 'label' => 'Informes', 'key' => 'informes'],
    ];
@endphp

<nav class="flex gap-1 mb-6 border-b border-gray-200">
    @foreach($tabs as $tab)
        <a href="{{ route($tab['route']) }}"
           class="px-4 py-2 text-sm font-medium rounded-t-lg -mb-px border-b-2 transition-colors
                  {{ ($active ?? '') === $tab['key']
                      ? 'border-primary text-primary bg-white'
                      : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }}">
            {{ $tab['label'] }}
        </a>
    @endforeach
</nav>
