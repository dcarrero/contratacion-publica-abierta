<x-layouts.app title="Red de relaciones — Contratación Abierta" :fullWidth="true">

    <h1 class="text-2xl font-bold text-gray-900 mb-2">Análisis de la contratación pública</h1>

    @include('analisis._subnav', ['active' => 'grafo'])

    <div class="bg-white rounded-lg shadow p-4 mb-4">
        <div class="flex flex-wrap items-center gap-4">
            <div>
                <label for="ccaa-select" class="text-sm font-medium text-gray-700">Comunidad autónoma:</label>
                <select id="ccaa-select" class="ml-2 text-sm border-gray-300 rounded px-3 py-1.5">
                    <option value="nacional">Toda España</option>
                    @foreach($ccaaList as $ca)
                        <option value="{{ $ca->nuts }}">{{ $ca->nombre }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex items-center gap-4 text-xs text-gray-500">
                <span class="inline-flex items-center gap-1">
                    <span class="w-3 h-3 rounded-full bg-blue-500 inline-block"></span> Organismo
                </span>
                <span class="inline-flex items-center gap-1">
                    <span class="w-3 h-3 rounded-full bg-orange-500 inline-block"></span> Adjudicatario
                </span>
                <span>Grosor = importe de la relación</span>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow overflow-hidden" style="position: relative;">
        <div id="grafo-container" style="width: 100%; height: 650px;"></div>
        <div id="grafo-loading" class="absolute inset-0 flex items-center justify-center bg-white bg-opacity-80" style="display: none;">
            <span class="text-gray-500 text-sm">Cargando grafo...</span>
        </div>
        <div id="grafo-empty" class="absolute inset-0 flex items-center justify-center" style="display: none;">
            <div class="text-center text-gray-500">
                <p class="font-medium">No hay datos del grafo disponibles.</p>
                <p class="text-sm mt-1">Ejecuta <code class="bg-gray-100 px-1 rounded">php artisan stats:recalculate --entity=grafo</code></p>
            </div>
        </div>
    </div>

    {{-- Tooltip --}}
    <div id="grafo-tooltip" style="position: fixed; display: none; background: white; border: 1px solid #ddd; border-radius: 6px; padding: 8px 12px; font-size: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.15); pointer-events: none; z-index: 1000; max-width: 350px;"></div>

    {{-- Info panel --}}
    <div id="grafo-info" class="mt-4 bg-white rounded-lg shadow p-4" style="display: none;">
        <h3 class="font-semibold text-gray-900 mb-2" id="info-title"></h3>
        <div id="info-body" class="text-sm text-gray-600"></div>
    </div>

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/d3@7/dist/d3.min.js"></script>
<script>
(function() {
    const container = document.getElementById('grafo-container');
    const tooltip = document.getElementById('grafo-tooltip');
    const loading = document.getElementById('grafo-loading');
    const empty = document.getElementById('grafo-empty');
    const infoPanel = document.getElementById('grafo-info');
    const infoTitle = document.getElementById('info-title');
    const infoBody = document.getElementById('info-body');
    const select = document.getElementById('ccaa-select');

    let simulation = null;

    function formatImporte(amount) {
        if (amount >= 1e9) return (amount / 1e9).toFixed(1).replace('.', ',') + ' Md€';
        if (amount >= 1e6) return (amount / 1e6).toFixed(1).replace('.', ',') + ' M€';
        if (amount >= 1e3) return (amount / 1e3).toFixed(1).replace('.', ',') + ' K€';
        return Math.round(amount).toLocaleString('es-ES') + ' €';
    }

    function loadGrafo(ccaa) {
        loading.style.display = 'flex';
        empty.style.display = 'none';
        infoPanel.style.display = 'none';
        container.innerHTML = '';

        if (simulation) { simulation.stop(); simulation = null; }

        const url = '{{ route("grafo.data") }}?ccaa=' + encodeURIComponent(ccaa);

        fetch(url)
            .then(r => r.json())
            .then(data => {
                loading.style.display = 'none';
                if (!data.nodes || data.nodes.length === 0) {
                    empty.style.display = 'flex';
                    return;
                }
                renderGrafo(data);
            })
            .catch(() => {
                loading.style.display = 'none';
                empty.style.display = 'flex';
            });
    }

    function renderGrafo(data) {
        const width = container.clientWidth;
        const height = container.clientHeight || 650;

        const svg = d3.select(container)
            .append('svg')
            .attr('width', width)
            .attr('height', height)
            .attr('viewBox', [0, 0, width, height]);

        // Zoom
        const g = svg.append('g');
        svg.call(d3.zoom()
            .scaleExtent([0.2, 5])
            .on('zoom', (e) => g.attr('transform', e.transform)));

        // Escalas
        const maxImporteLink = d3.max(data.links, d => d.total_importe) || 1;
        const maxImporteNode = d3.max(data.nodes, d => d.total_importe) || 1;

        const linkWidth = d3.scaleLinear()
            .domain([0, maxImporteLink])
            .range([0.5, 6]);

        const nodeRadius = d3.scaleSqrt()
            .domain([0, maxImporteNode])
            .range([4, 25]);

        const colorOrg = '#3b82f6';
        const colorAdj = '#f97316';

        // Links
        const link = g.append('g')
            .selectAll('line')
            .data(data.links)
            .join('line')
            .attr('stroke', '#cbd5e1')
            .attr('stroke-opacity', 0.6)
            .attr('stroke-width', d => linkWidth(d.total_importe));

        // Nodos
        const node = g.append('g')
            .selectAll('circle')
            .data(data.nodes)
            .join('circle')
            .attr('r', d => nodeRadius(d.total_importe))
            .attr('fill', d => d.tipo === 'organismo' ? colorOrg : colorAdj)
            .attr('stroke', '#fff')
            .attr('stroke-width', 1.5)
            .attr('cursor', 'pointer')
            .call(d3.drag()
                .on('start', dragstarted)
                .on('drag', dragged)
                .on('end', dragended));

        // Labels para nodos grandes
        const labels = g.append('g')
            .selectAll('text')
            .data(data.nodes.filter(d => nodeRadius(d.total_importe) > 10))
            .join('text')
            .text(d => d.label.length > 25 ? d.label.substring(0, 25) + '...' : d.label)
            .attr('font-size', '8px')
            .attr('fill', '#374151')
            .attr('text-anchor', 'middle')
            .attr('dy', d => nodeRadius(d.total_importe) + 12)
            .attr('pointer-events', 'none');

        // Tooltip en hover
        node.on('mouseover', function(event, d) {
            tooltip.innerHTML = '<strong>' + d.label + '</strong><br>' +
                '<span style="color:#888">' + d.nif + '</span><br>' +
                (d.tipo === 'organismo' ? 'Organismo' : 'Adjudicatario') + '<br>' +
                'Importe: ' + formatImporte(d.total_importe);
            tooltip.style.display = 'block';
            tooltip.style.left = event.clientX + 12 + 'px';
            tooltip.style.top = event.clientY - 10 + 'px';

            d3.select(this).attr('stroke', '#000').attr('stroke-width', 2);
        })
        .on('mousemove', function(event) {
            tooltip.style.left = event.clientX + 12 + 'px';
            tooltip.style.top = event.clientY - 10 + 'px';
        })
        .on('mouseout', function() {
            tooltip.style.display = 'none';
            d3.select(this).attr('stroke', '#fff').attr('stroke-width', 1.5);
        });

        // Click en nodo: mostrar relaciones
        node.on('click', function(event, d) {
            event.stopPropagation();
            const connections = data.links.filter(l =>
                (typeof l.source === 'object' ? l.source.id : l.source) === d.id ||
                (typeof l.target === 'object' ? l.target.id : l.target) === d.id
            );

            const tipoLabel = d.tipo === 'organismo' ? 'Organismo' : 'Adjudicatario';
            const url = d.tipo === 'organismo'
                ? '/organismos/' + encodeURIComponent(d.nif)
                : '/empresas/' + encodeURIComponent(d.nif);

            infoTitle.innerHTML = '<a href="' + url + '" class="text-primary hover:underline">' + d.label + '</a>' +
                ' <span class="text-xs text-gray-400">(' + tipoLabel + ' · ' + d.nif + ')</span>';

            let html = '<p class="mb-2">Importe total en relaciones mostradas: <strong>' + formatImporte(d.total_importe) + '</strong></p>';
            html += '<table class="min-w-full text-xs"><thead class="bg-gray-50"><tr><th class="px-2 py-1 text-left">Relación con</th><th class="px-2 py-1 text-right">Contratos</th><th class="px-2 py-1 text-right">Importe</th></tr></thead><tbody>';

            connections.sort((a, b) => b.total_importe - a.total_importe);
            connections.forEach(c => {
                const src = typeof c.source === 'object' ? c.source : data.nodes.find(n => n.id === c.source);
                const tgt = typeof c.target === 'object' ? c.target : data.nodes.find(n => n.id === c.target);
                const other = (src && src.id === d.id) ? tgt : src;
                if (!other) return;
                const otherUrl = other.tipo === 'organismo'
                    ? '/organismos/' + encodeURIComponent(other.nif)
                    : '/empresas/' + encodeURIComponent(other.nif);
                html += '<tr class="border-t"><td class="px-2 py-1"><a href="' + otherUrl + '" class="text-primary hover:underline">' + other.label + '</a></td>' +
                    '<td class="px-2 py-1 text-right">' + c.num_contratos.toLocaleString('es-ES') + '</td>' +
                    '<td class="px-2 py-1 text-right">' + formatImporte(c.total_importe) + '</td></tr>';
            });
            html += '</tbody></table>';
            infoBody.innerHTML = html;
            infoPanel.style.display = 'block';

            // Highlight
            node.attr('opacity', n => {
                if (n.id === d.id) return 1;
                return connections.some(c =>
                    (typeof c.source === 'object' ? c.source.id : c.source) === n.id ||
                    (typeof c.target === 'object' ? c.target.id : c.target) === n.id
                ) ? 1 : 0.15;
            });
            link.attr('opacity', l => {
                const sid = typeof l.source === 'object' ? l.source.id : l.source;
                const tid = typeof l.target === 'object' ? l.target.id : l.target;
                return (sid === d.id || tid === d.id) ? 1 : 0.05;
            });
            labels.attr('opacity', n => {
                if (n.id === d.id) return 1;
                return connections.some(c =>
                    (typeof c.source === 'object' ? c.source.id : c.source) === n.id ||
                    (typeof c.target === 'object' ? c.target.id : c.target) === n.id
                ) ? 1 : 0.15;
            });
        });

        // Click en fondo: reset
        svg.on('click', function() {
            node.attr('opacity', 1);
            link.attr('opacity', 0.6);
            labels.attr('opacity', 1);
            infoPanel.style.display = 'none';
        });

        // Link tooltip
        link.on('mouseover', function(event, d) {
            const src = typeof d.source === 'object' ? d.source : data.nodes.find(n => n.id === d.source);
            const tgt = typeof d.target === 'object' ? d.target : data.nodes.find(n => n.id === d.target);
            tooltip.innerHTML = (src ? src.label : '?') + ' ↔ ' + (tgt ? tgt.label : '?') +
                '<br>' + d.num_contratos + ' contratos · ' + formatImporte(d.total_importe);
            tooltip.style.display = 'block';
            tooltip.style.left = event.clientX + 12 + 'px';
            tooltip.style.top = event.clientY - 10 + 'px';
            d3.select(this).attr('stroke', '#64748b').attr('stroke-opacity', 1);
        })
        .on('mousemove', function(event) {
            tooltip.style.left = event.clientX + 12 + 'px';
            tooltip.style.top = event.clientY - 10 + 'px';
        })
        .on('mouseout', function() {
            tooltip.style.display = 'none';
            d3.select(this).attr('stroke', '#cbd5e1').attr('stroke-opacity', 0.6);
        });

        // Simulación
        simulation = d3.forceSimulation(data.nodes)
            .force('link', d3.forceLink(data.links).id(d => d.id).distance(80))
            .force('charge', d3.forceManyBody().strength(-200))
            .force('center', d3.forceCenter(width / 2, height / 2))
            .force('collision', d3.forceCollide().radius(d => nodeRadius(d.total_importe) + 2))
            .on('tick', () => {
                link
                    .attr('x1', d => d.source.x)
                    .attr('y1', d => d.source.y)
                    .attr('x2', d => d.target.x)
                    .attr('y2', d => d.target.y);
                node
                    .attr('cx', d => d.x)
                    .attr('cy', d => d.y);
                labels
                    .attr('x', d => d.x)
                    .attr('y', d => d.y);
            });

        function dragstarted(event, d) {
            if (!event.active) simulation.alphaTarget(0.3).restart();
            d.fx = d.x; d.fy = d.y;
        }
        function dragged(event, d) {
            d.fx = event.x; d.fy = event.y;
        }
        function dragended(event, d) {
            if (!event.active) simulation.alphaTarget(0);
            d.fx = null; d.fy = null;
        }
    }

    // Event
    select.addEventListener('change', function() { loadGrafo(this.value); });

    // Load
    loadGrafo('nacional');
})();
</script>
@endpush

</x-layouts.app>
