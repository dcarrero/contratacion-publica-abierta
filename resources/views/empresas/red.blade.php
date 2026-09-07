@php
    $meta = $red['meta'];
    $metaDesc = 'Red de relaciones de '.$empresa->nombre.' (NIF '.$empresa->nif.'): organismos que le adjudican '
        .'y otras empresas que contratan con esos mismos organismos, entre '.$meta['anio_desde'].' y '.$meta['anio_hasta'].'.';
@endphp
<x-layouts.app :title="'Red de relaciones de ' . $empresa->nombre . ' — Contratación Abierta'" :metaDescription="$metaDesc">

    <x-seo.breadcrumb :items="[
        ['name' => 'Adjudicatarios', 'url' => route('empresas.index')],
        ['name' => $empresa->nombre, 'url' => route('empresas.show', $empresa->nif)],
        ['name' => 'Red de relaciones', 'url' => route('empresas.red', $empresa->nif)],
    ]" />

    <nav class="text-sm text-gray-500 mb-4">
        <a href="{{ route('empresas.index') }}" class="hover:text-primary">Empresas</a>
        <span class="mx-1">/</span>
        <a href="{{ route('empresas.show', $empresa->nif) }}" class="hover:text-primary">{{ $empresa->nif }}</a>
        <span class="mx-1">/</span>
        <span class="text-gray-700">Red de relaciones</span>
    </nav>

    <div class="mb-4">
        <h1 class="font-serif text-2xl sm:text-3xl lg:text-4xl font-semibold text-gray-900 leading-tight tracking-tight">Red de relaciones de {{ $empresa->nombre }}</h1>
        <p class="mt-2 text-sm text-gray-500"><span class="font-mono">{{ $empresa->nif }}</span></p>
    </div>

    <x-entidad.pestanas
        activa="red"
        :url-ficha="route('empresas.show', $empresa->nif)"
        :url-historico="route('empresas.historico', $empresa->nif)"
        :url-red="route('empresas.red', $empresa->nif)" />

    @if($red['nodes'])
        <p class="text-sm text-gray-500 mb-4">
            Contratos de <strong>{{ $meta['anio_desde'] }}–{{ $meta['anio_hasta'] }}</strong>:
            {{ $meta['directas'] }} {{ $meta['directas'] === 1 ? 'organismo' : 'organismos' }} le adjudican, y
            {{ $meta['compartidas'] }} {{ $meta['compartidas'] === 1 ? 'empresa contrata' : 'empresas contratan' }}
            con esos mismos organismos.
        </p>
    @endif

    <x-red.grafo :red="$red" />

    <p class="mt-4 text-xs text-gray-400">
        La red muestra las diez relaciones de mayor importe y, por cada una, sus cinco principales
        contrapartes. Compartir organismo no implica competir por los mismos contratos.
    </p>

</x-layouts.app>
