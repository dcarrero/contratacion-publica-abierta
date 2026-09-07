@props([
    'red',              // salida de RedRelacionesBuilder
    'rutaOrganismo' => 'organismos.show',
    'rutaEmpresa' => 'empresas.show',
])
@php
    $meta = $red['meta'];
    $esEmpresa = $meta['tipo_centro'] === 'adjudicatario';
@endphp

@if($red['nodes'] === [])
    <div class="bg-white rounded-lg shadow p-8 text-center">
        <p class="text-gray-900 font-semibold">Sin relaciones en {{ $meta['anio_desde'] }}–{{ $meta['anio_hasta'] }}</p>
        <p class="mt-2 text-sm text-gray-500">
            La red se construye con los contratos de los últimos tres años con actividad.
        </p>
    </div>
@else
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="px-5 py-3 border-b border-gray-100 flex flex-wrap items-center gap-x-5 gap-y-2 text-xs text-gray-500">
            <span class="inline-flex items-center gap-1.5">
                <span class="w-3 h-3 rounded-full bg-red-600 inline-block"></span>
                {{ $esEmpresa ? 'Esta empresa' : 'Este organismo' }}
            </span>
            <span class="inline-flex items-center gap-1.5">
                <span class="w-3 h-3 rounded-full {{ $esEmpresa ? 'bg-blue-500' : 'bg-orange-500' }} inline-block"></span>
                {{ $esEmpresa ? 'Organismos que le adjudican' : 'Sus adjudicatarios' }}
            </span>
            <span class="inline-flex items-center gap-1.5">
                <span class="w-3 h-3 rounded-full bg-gray-400 inline-block"></span>
                {{ $esEmpresa ? 'Otras empresas en esos organismos' : 'Otros organismos con esos proveedores' }}
            </span>
            <span class="ml-auto">Grosor = importe · Arrastra los nodos</span>
        </div>

        <div id="red-grafo" style="width: 100%; height: 560px; position: relative;">
            <div id="red-grafo-vacio" class="absolute inset-0 items-center justify-center text-sm text-gray-500 hidden">
                No se ha podido dibujar la red.
            </div>
        </div>
    </div>

    <div id="red-detalle" class="mt-4 bg-white rounded-lg shadow p-4 text-sm" style="display:none;">
        <p class="font-semibold text-gray-900" id="red-detalle-titulo"></p>
        <p class="mt-1 text-gray-600" id="red-detalle-cuerpo"></p>
    </div>

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/d3@7/dist/d3.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const datos = @json($red);
    const contenedor = document.getElementById('red-grafo');

    if (!contenedor || typeof d3 === 'undefined' || !datos.nodes.length) {
        const vacio = document.getElementById('red-grafo-vacio');
        if (vacio) vacio.classList.replace('hidden', 'flex');
        return;
    }

    const ancho = contenedor.clientWidth;
    const alto = contenedor.clientHeight;
    const rutaOrganismo = @json(route($rutaOrganismo, ['__NIF__'])).replace('__NIF__', '');
    const rutaEmpresa = @json(route($rutaEmpresa, ['__NIF__'])).replace('__NIF__', '');

    // Copias: d3.forceLink reescribe source/target con referencias a los nodos.
    const nodos = datos.nodes.map(n => ({...n}));
    const enlaces = datos.links.map(l => ({...l}));

    // El radio se normaliza DENTRO de cada nivel. Con una escala global, los nodos del
    // segundo nivel —que acumulan todo lo que mueven en sus organismos— salían más gordos
    // que las relaciones directas de la entidad, que son el asunto de la página.
    const maxPorNivel = {};
    nodos.forEach(n => {
        maxPorNivel[n.nivel] = Math.max(maxPorNivel[n.nivel] || 0, n.total_importe || 0);
    });

    const radio = n => {
        if (n.nivel === 0) return 18;
        const proporcion = Math.sqrt((n.total_importe || 0) / (maxPorNivel[n.nivel] || 1));
        return n.nivel === 1 ? 8 + 12 * proporcion : 4 + 6 * proporcion;
    };

    const maxEnlace = d3.max(enlaces, l => l.total_importe) || 1;
    const grosor = l => 1 + 5 * Math.sqrt(l.total_importe / maxEnlace);

    const color = n => n.nivel === 0 ? '#dc2626' : (n.tipo === 'organismo' ? '#2563eb' : '#f97316');

    const svg = d3.select(contenedor).append('svg')
        .attr('width', ancho)
        .attr('height', alto)
        .attr('viewBox', [0, 0, ancho, alto]);

    const capa = svg.append('g');

    svg.call(d3.zoom().scaleExtent([0.4, 4]).on('zoom', e => capa.attr('transform', e.transform)));

    const simulacion = d3.forceSimulation(nodos)
        .force('link', d3.forceLink(enlaces).id(d => d.id).distance(d => d.directo ? 150 : 70).strength(d => d.directo ? 0.7 : 0.15))
        .force('charge', d3.forceManyBody().strength(d => d.nivel === 2 ? -120 : -450))
        .force('center', d3.forceCenter(ancho / 2, alto / 2))
        .force('collide', d3.forceCollide().radius(d => radio(d) + 6));

    const linea = capa.append('g')
        .attr('stroke', '#cbd5e1')
        .selectAll('line')
        .data(enlaces)
        .join('line')
        .attr('stroke-width', grosor)
        .attr('stroke-opacity', d => d.directo ? 0.75 : 0.35);

    const nodo = capa.append('g')
        .selectAll('g')
        .data(nodos)
        .join('g')
        .style('cursor', 'pointer')
        .call(d3.drag()
            .on('start', (evento, d) => {
                if (!evento.active) simulacion.alphaTarget(0.3).restart();
                d.fx = d.x; d.fy = d.y;
            })
            .on('drag', (evento, d) => { d.fx = evento.x; d.fy = evento.y; })
            .on('end', (evento, d) => {
                if (!evento.active) simulacion.alphaTarget(0);
                d.fx = null; d.fy = null;
            }));

    nodo.append('circle')
        .attr('r', radio)
        .attr('fill', d => d.nivel === 2 ? '#94a3b8' : color(d))
        .attr('stroke', '#fff')
        .attr('stroke-width', 1.5);

    // Sólo se etiqueta el centro y el primer nivel: con todas las etiquetas el grafo se
    // vuelve ilegible, y el segundo nivel se consulta al pulsar.
    nodo.filter(d => d.nivel < 2)
        .append('text')
        .text(d => d.label.length > 34 ? d.label.slice(0, 33) + '…' : d.label)
        .attr('x', d => radio(d) + 4)
        .attr('y', 4)
        .attr('font-size', d => d.nivel === 0 ? 13 : 11)
        .attr('font-weight', d => d.nivel === 0 ? 600 : 400)
        .attr('fill', '#374151')
        .attr('paint-order', 'stroke')
        .attr('stroke', '#fff')
        .attr('stroke-width', 3);

    nodo.append('title').text(d => d.label + ' · ' + window.formatImporteCorto(d.total_importe));

    const detalle = document.getElementById('red-detalle');
    const detalleTitulo = document.getElementById('red-detalle-titulo');
    const detalleCuerpo = document.getElementById('red-detalle-cuerpo');

    nodo.on('click', (evento, d) => {
        detalle.style.display = 'block';
        detalleTitulo.textContent = d.label;

        const relacionados = enlaces.filter(l => l.source.id === d.id || l.target.id === d.id);
        const importe = window.formatImporteCorto(d.total_importe);
        const contratos = relacionados.reduce((total, l) => total + l.num_contratos, 0);
        const base = d.tipo === 'organismo' ? rutaOrganismo : rutaEmpresa;

        detalleCuerpo.innerHTML = '';
        detalleCuerpo.append(
            document.createTextNode(
                (d.nivel === 0 ? 'Entidad central' : (d.nivel === 1 ? 'Relación directa' : 'Comparte contrapartes'))
                + ' · ' + importe + ' en ' + contratos.toLocaleString('es-ES') + ' contratos de la red. '
            )
        );

        if (d.nif) {
            const enlace = document.createElement('a');
            enlace.href = base + encodeURIComponent(d.nif);
            enlace.className = 'text-primary hover:underline font-medium';
            enlace.textContent = 'Ver ficha →';
            detalleCuerpo.append(enlace);
        }
    });

    simulacion.on('tick', () => {
        linea
            .attr('x1', d => d.source.x).attr('y1', d => d.source.y)
            .attr('x2', d => d.target.x).attr('y2', d => d.target.y);
        nodo.attr('transform', d => `translate(${d.x},${d.y})`);
    });
});
</script>
@endpush
@endif
