@php
    $tabs = [
        ['route' => 'admin.dashboard', 'label' => 'Dashboard', 'key' => 'dashboard'],
        ['route' => 'admin.import-logs', 'label' => 'Import Logs', 'key' => 'import-logs'],
        ['route' => 'admin.fuentes', 'label' => 'Fuentes', 'key' => 'fuentes'],
        ['route' => 'admin.commands', 'label' => 'Comandos', 'key' => 'commands'],
        ['route' => 'admin.docs', 'label' => 'Docs', 'key' => 'docs'],
    ];
@endphp

<nav class="bg-gray-800 border-b border-gray-700">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex gap-1 overflow-x-auto">
            @foreach($tabs as $tab)
                <a href="{{ route($tab['route']) }}"
                   class="px-4 py-2.5 text-sm font-medium whitespace-nowrap transition-colors
                          {{ ($adminActive ?? '') === $tab['key']
                              ? 'text-white bg-gray-700 border-b-2 border-blue-400'
                              : 'text-gray-400 hover:text-gray-200 hover:bg-gray-700/50' }}">
                    {{ $tab['label'] }}
                </a>
            @endforeach
        </div>
    </div>
</nav>
