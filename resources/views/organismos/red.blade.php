@php
    $meta = $red['meta'];
    $metaDesc = 'Red de relaciones de '.$organismo->nombre.' (NIF '.$organismo->nif.'): adjudicatarios a los que contrata '
        .'y otros organismos que contratan con esos mismos proveedores, entre '.$meta['anio_desde'].' y '.$meta['anio_hasta'].'.';
@endphp
<x-layouts.app :title="'Red de relaciones de ' . $organismo->nombre . ' — Contratación Abierta'" :metaDescription="$metaDesc">

    <x-seo.breadcrumb :items="[
        ['name' => 'Organismos', 'url' => route('organismos.index')],
        ['name' => $organismo->nombre, 'url' => route('organismos.show', $organismo->nif)],
        ['name' => 'Red de relaciones', 'url' => route('organismos.red', $organismo->nif)],
    ]" />

    <nav class="text-sm text-gray-500 mb-4">
        <a href="{{ route('organismos.index') }}" class="hover:text-primary">Organismos</a>
        <span class="mx-1">/</span>
        <a href="{{ route('organismos.show', $organismo->nif) }}" class="hover:text-primary">{{ $organismo->nif }}</a>
        <span class="mx-1">/</span>
        <span class="text-gray-700">Red de relaciones</span>
    </nav>

    <div class="mb-4">
        <h1 class="font-serif text-2xl sm:text-3xl lg:text-4xl font-semibold text-gray-900 leading-tight tracking-tight">Red de relaciones de {{ $organismo->nombre }}</h1>
        <p class="mt-2 text-sm text-gray-500"><span class="font-mono">{{ $organismo->nif }}</span></p>
    </div>

    <x-entidad.pestanas
        activa="red"
        :url-ficha="route('organismos.show', $organismo->nif)"
        :url-historico="route('organismos.historico', $organismo->nif)"
        :url-red="route('organismos.red', $organismo->nif)" />

    @if($red['nodes'])
        <p class="text-sm text-gray-500 mb-4">
            Contratos de <strong>{{ $meta['anio_desde'] }}–{{ $meta['anio_hasta'] }}</strong>:
            {{ $meta['directas'] }} {{ $meta['directas'] === 1 ? 'adjudicatario' : 'adjudicatarios' }} reciben sus contratos, y
            {{ $meta['compartidas'] }} {{ $meta['compartidas'] === 1 ? 'organismo contrata' : 'organismos contratan' }}
            con esos mismos proveedores.
        </p>
    @endif

    <x-red.grafo :red="$red" />

    <p class="mt-4 text-xs text-gray-400">
        La red muestra las diez relaciones de mayor importe y, por cada una, sus cinco principales
        contrapartes. Compartir proveedor no implica ninguna relación entre organismos.
    </p>

</x-layouts.app>
