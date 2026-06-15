@php
    $metaDesc = \Illuminate\Support\Str::limit($contrato->objeto, 120)
        .($contrato->organismo ? ' · '.$contrato->organismo->nombre : '')
        .($contrato->importe_adjudicacion ? ' · '.formatImporte($contrato->importe_adjudicacion) : '')
        .($contrato->adjudicatario ? ' · Adjudicado a '.$contrato->adjudicatario->nombre : '');
@endphp
<x-layouts.app :title="Str::limit($contrato->objeto, 60) . ' — Contratación Abierta'" :metaDescription="$metaDesc">

    <x-seo.breadcrumb :items="[
        ['name' => 'Contratos', 'url' => route('contratos.index')],
        ['name' => $contrato->expediente ?? $contrato->placsp_id, 'url' => url()->current()],
    ]" />

    {{-- Breadcrumb --}}
    <nav class="text-sm text-gray-500 mb-4">
        <a href="{{ route('contratos.index') }}" class="hover:text-primary">Contratos</a>
        <span class="mx-1">/</span>
        <span class="text-gray-700">{{ $contrato->expediente ?? $contrato->placsp_id }}</span>
    </nav>

    <div class="lg:grid lg:grid-cols-3 lg:gap-8">

        {{-- Columna principal (2/3) --}}
        <div class="lg:col-span-2 space-y-6">

            {{-- Header --}}
            <div>
                <h1 class="text-xl sm:text-2xl font-bold text-gray-900 leading-tight">{{ $contrato->objeto }}</h1>
                <div class="mt-3 flex flex-wrap gap-2">
                    @if($contrato->estado)
                        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold
                            {{ $contrato->estado === 'Adjudicada' ? 'bg-green-100 text-green-700' : '' }}
                            {{ $contrato->estado === 'Resuelta' ? 'bg-blue-100 text-blue-700' : '' }}
                            {{ $contrato->estado === 'Evaluación' ? 'bg-yellow-100 text-yellow-700' : '' }}
                            {{ $contrato->estado === 'Anulada' ? 'bg-red-100 text-red-700' : '' }}
                            {{ !in_array($contrato->estado, ['Adjudicada', 'Resuelta', 'Evaluación', 'Anulada']) ? 'bg-gray-100 text-gray-600' : '' }}
                        ">
                            {{ $contrato->estado }}
                        </span>
                    @endif
                    @if($contrato->es_menor)
                        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-amber-100 text-amber-700">
                            Contrato menor
                        </span>
                    @endif
                    @if($contrato->tipo_contrato)
                        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-600">
                            {{ $tipos_contrato[$contrato->tipo_contrato] ?? $contrato->tipo_contrato }}
                        </span>
                    @endif
                </div>
            </div>

            {{-- Datos generales --}}
            <div class="bg-white rounded-lg shadow p-5">
                <h2 class="text-sm font-semibold text-gray-900 uppercase tracking-wide mb-4">Datos generales</h2>
                <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-4 text-sm">
                    @if($contrato->expediente)
                    <div>
                        <dt class="text-gray-500">Expediente</dt>
                        <dd class="text-gray-900 font-mono text-xs mt-0.5">{{ $contrato->expediente }}</dd>
                    </div>
                    @endif
                    @if($contrato->tipo_contrato)
                    <div>
                        <dt class="text-gray-500">Tipo de contrato</dt>
                        <dd class="text-gray-900 mt-0.5">{{ $tipos_contrato[$contrato->tipo_contrato] ?? $contrato->tipo_contrato }}</dd>
                    </div>
                    @endif
                    @if($contrato->procedimiento)
                    <div>
                        <dt class="text-gray-500">Procedimiento</dt>
                        <dd class="text-gray-900 mt-0.5">{{ $procedimientos[$contrato->procedimiento] ?? $contrato->procedimiento }}</dd>
                    </div>
                    @endif
                    @if($contrato->duracion)
                    <div>
                        <dt class="text-gray-500">Duración</dt>
                        <dd class="text-gray-900 mt-0.5">{{ $contrato->duracion }}</dd>
                    </div>
                    @endif
                    @if($contrato->num_ofertas)
                    <div>
                        <dt class="text-gray-500">Ofertas recibidas</dt>
                        <dd class="text-gray-900 mt-0.5">{{ $contrato->num_ofertas }}</dd>
                    </div>
                    @endif
                    @if($contrato->cpv)
                    <div>
                        <dt class="text-gray-500">CPV</dt>
                        <dd class="text-gray-900 font-mono text-xs mt-0.5">{{ $contrato->cpv }}</dd>
                    </div>
                    @endif
                    @if($contrato->lugar_ejecucion)
                    <div class="sm:col-span-2">
                        <dt class="text-gray-500">Lugar de ejecución</dt>
                        <dd class="text-gray-900 mt-0.5">{{ $contrato->lugar_ejecucion }}</dd>
                    </div>
                    @endif
                    @if($contrato->nuts)
                    <div>
                        <dt class="text-gray-500">NUTS</dt>
                        <dd class="text-gray-900 font-mono text-xs mt-0.5">{{ $contrato->nuts }}</dd>
                    </div>
                    @endif
                </dl>
            </div>

            {{-- Importes --}}
            @if($contrato->importe_licitacion || $contrato->importe_adjudicacion)
            <div class="bg-white rounded-lg shadow p-5">
                <h2 class="text-sm font-semibold text-gray-900 uppercase tracking-wide mb-4">Importes</h2>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200">
                                <th class="text-left py-2 pr-4 text-gray-500 font-medium"></th>
                                <th class="text-right py-2 px-4 text-gray-500 font-medium">Sin IVA</th>
                                <th class="text-right py-2 pl-4 text-gray-500 font-medium">Con IVA</th>
                            </tr>
                        </thead>
                        <tbody>
                            @if($contrato->importe_licitacion || $contrato->importe_licitacion_con_iva)
                            <tr class="border-b border-gray-100">
                                <td class="py-2.5 pr-4 text-gray-600">Licitación</td>
                                <td class="py-2.5 px-4 text-right font-medium">{{ formatImporte($contrato->importe_licitacion) }}</td>
                                <td class="py-2.5 pl-4 text-right font-medium">{{ formatImporte($contrato->importe_licitacion_con_iva) }}</td>
                            </tr>
                            @endif
                            @if($contrato->importe_adjudicacion || $contrato->importe_adjudicacion_con_iva)
                            <tr>
                                <td class="py-2.5 pr-4 text-gray-600">Adjudicación</td>
                                <td class="py-2.5 px-4 text-right font-semibold text-gray-900">{{ formatImporte($contrato->importe_adjudicacion) }}</td>
                                <td class="py-2.5 pl-4 text-right font-semibold text-gray-900">{{ formatImporte($contrato->importe_adjudicacion_con_iva) }}</td>
                            </tr>
                            @endif
                        </tbody>
                    </table>
                </div>
            </div>
            @endif

            {{-- Fechas --}}
            <div class="bg-white rounded-lg shadow p-5">
                <h2 class="text-sm font-semibold text-gray-900 uppercase tracking-wide mb-4">Fechas</h2>
                <dl class="grid grid-cols-2 sm:grid-cols-4 gap-4 text-sm">
                    <div>
                        <dt class="text-gray-500">Publicación</dt>
                        <dd class="text-gray-900 font-medium mt-0.5">
                            {{ formatFecha($contrato->fecha_publicacion) }}
                            @if($contrato->fecha_publicacion && $contrato->fecha_publicacion->month === 1 && $contrato->fecha_publicacion->day === 1 && str_starts_with($contrato->placsp_id, 'ARA-'))
                                <span class="text-xs text-amber-600" title="La fuente de datos de Aragón no proporciona fecha exacta, solo el año del ejercicio">(fecha estimada)</span>
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Límite</dt>
                        <dd class="text-gray-900 font-medium mt-0.5">{{ formatFecha($contrato->fecha_limite) }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Adjudicación</dt>
                        <dd class="text-gray-900 font-medium mt-0.5">{{ formatFecha($contrato->fecha_adjudicacion) }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Formalización</dt>
                        <dd class="text-gray-900 font-medium mt-0.5">{{ formatFecha($contrato->fecha_formalizacion) }}</dd>
                    </div>
                </dl>
            </div>

            {{-- Historial de versiones --}}
            @if($contrato->historial->isNotEmpty())
            <div class="bg-white rounded-lg shadow p-5">
                <h2 class="text-sm font-semibold text-gray-900 uppercase tracking-wide mb-4">Historial de versiones</h2>
                <div class="space-y-3">
                    @foreach($contrato->historial as $version)
                    <div class="border border-gray-100 rounded p-3 text-sm">
                        <div class="flex items-center justify-between mb-1">
                            <span class="text-gray-500 text-xs">{{ formatFecha($version->fecha_updated) }}</span>
                        </div>
                        @if($version->datos_json)
                        <details class="mt-1">
                            <summary class="text-xs text-primary cursor-pointer hover:underline">Ver datos de esta versión</summary>
                            <pre class="mt-2 text-xs text-gray-600 bg-gray-50 rounded p-2 overflow-x-auto">{{ json_encode($version->datos_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                        </details>
                        @endif
                    </div>
                    @endforeach
                </div>
            </div>
            @endif

        </div>

        {{-- Sidebar (1/3) --}}
        <div class="mt-6 lg:mt-0 space-y-6">

            {{-- Fuente oficial --}}
            <div class="bg-white rounded-lg shadow p-5">
                <h2 class="text-sm font-semibold text-gray-900 uppercase tracking-wide mb-4">Fuente oficial</h2>
                @if($contrato->url_placsp)
                    <a href="{{ $contrato->url_placsp }}" target="_blank" rel="noopener"
                       class="block w-full text-center bg-primary text-white font-semibold py-2.5 px-4 rounded-lg hover:bg-primary-dark transition mb-4">
                        Ver en fuente oficial
                    </a>
                @else
                    <div class="text-xs text-gray-400 bg-gray-50 rounded p-3 mb-4">
                        La fuente de datos de este contrato no proporciona enlace a la publicación original.
                    </div>
                @endif
                <dl class="text-sm space-y-3">
                    <div>
                        <dt class="text-gray-500">ID PLACSP</dt>
                        <dd class="text-gray-900 font-mono text-xs mt-0.5 break-all">{{ $contrato->placsp_id }}</dd>
                    </div>
                    @if($contrato->fuenteDatos)
                    <div>
                        <dt class="text-gray-500">Fuente de datos</dt>
                        <dd class="text-gray-900 text-xs mt-0.5">{{ $contrato->fuenteDatos->nombre }}</dd>
                    </div>
                    @endif
                    <div>
                        <dt class="text-gray-500">Versión</dt>
                        <dd class="text-gray-900 mt-0.5">{{ $contrato->version ?? 1 }}</dd>
                    </div>
                    @if($contrato->updated_at)
                    <div>
                        <dt class="text-gray-500">Última actualización</dt>
                        <dd class="text-gray-900 text-xs mt-0.5">{{ $contrato->updated_at->format('d/m/Y H:i') }}</dd>
                    </div>
                    @endif
                </dl>
            </div>

            {{-- Órgano contratante --}}
            @if($contrato->organismo)
            <div class="bg-white rounded-lg shadow p-5">
                <h2 class="text-sm font-semibold text-gray-900 uppercase tracking-wide mb-4">Órgano contratante</h2>
                <dl class="text-sm space-y-2">
                    <div>
                        <dt class="text-gray-500">Nombre</dt>
                        <dd class="text-gray-900 mt-0.5">
                            <a href="{{ route('organismos.show', $contrato->organismo->nif) }}" class="hover:text-primary hover:underline">{{ $contrato->organismo->nombre }}</a>
                        </dd>
                    </div>
                    @if($contrato->organismo->nif)
                    <div>
                        <dt class="text-gray-500">NIF</dt>
                        <dd class="text-gray-900 font-mono text-xs mt-0.5">{{ $contrato->organismo->nif }}</dd>
                    </div>
                    @endif
                </dl>
            </div>
            @endif

            {{-- Adjudicatario --}}
            @if($contrato->adjudicatario)
            <div class="bg-white rounded-lg shadow p-5">
                <h2 class="text-sm font-semibold text-gray-900 uppercase tracking-wide mb-4">Adjudicatario</h2>
                <dl class="text-sm space-y-2">
                    <div>
                        <dt class="text-gray-500">Nombre</dt>
                        <dd class="text-gray-900 mt-0.5">
                            <a href="{{ route('empresas.show', $contrato->adjudicatario->nif) }}" class="hover:text-primary hover:underline">{{ $contrato->adjudicatario->nombre }}</a>
                        </dd>
                    </div>
                    @if($contrato->adjudicatario->nif)
                    <div>
                        <dt class="text-gray-500">NIF</dt>
                        <dd class="text-gray-900 font-mono text-xs mt-0.5">{{ $contrato->adjudicatario->nif }}</dd>
                    </div>
                    @endif
                    @if($contrato->adjudicatario->es_pyme)
                    <div>
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-700">PYME</span>
                    </div>
                    @endif
                </dl>
            </div>
            @endif

        </div>
    </div>

</x-layouts.app>
