<x-layouts.app :title="'Mapa de contratación pública — Contratación Abierta'" :fullWidth="true">

@push('styles')
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="" />
<style>
    #mapa-container {
        height: calc(100vh - 4rem);
        display: grid;
        grid-template-columns: 1fr 320px;
    }
    @media (max-width: 1023px) {
        #mapa-container {
            grid-template-columns: 1fr;
            grid-template-rows: 1fr auto;
        }
    }
    .leaflet-container { background: #e8f4f8; }
    .leaflet-container img { max-width: none !important; max-height: none !important; }
    .leaflet-container .leaflet-control-zoom a { line-height: 26px !important; }
    .leyenda-gradient {
        height: 12px;
        background: linear-gradient(to right, #FEE2E2, #FECACA, #FCA5A5, #F87171, #EF4444, #DC2626, #B91C1C, #991B1B, #C8102E);
        border-radius: 4px;
    }
</style>
@endpush

<div id="mapa-container" x-data="mapaApp()">

    {{-- Mapa --}}
    <div id="mapa"></div>

    {{-- Sidebar --}}
    <div class="bg-white border-l border-gray-200 overflow-y-auto p-4 flex flex-col gap-4">

        {{-- Botón volver --}}
        <button x-show="view === 'provincias'"
                x-on:click="backToCcaa()"
                class="flex items-center gap-1 text-sm text-primary hover:text-primary-dark font-medium transition">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
            </svg>
            Volver a comunidades
        </button>

        {{-- Título --}}
        <div>
            <h2 class="text-lg font-bold text-gray-800" x-text="sidebarTitle"></h2>
            <p class="text-sm text-gray-500" x-text="sidebarSubtitle"></p>
        </div>

        {{-- Info hover --}}
        <div x-show="hoveredRegion" x-transition
             class="bg-gray-50 rounded-lg p-3 border border-gray-200">
            <p class="font-semibold text-gray-800" x-text="hoveredRegion?.nombre"></p>
            <div class="mt-1 grid grid-cols-2 gap-2 text-sm">
                <div>
                    <span class="text-gray-500">Contratos</span>
                    <p class="font-medium" x-text="formatNumber(hoveredRegion?.total_contratos)"></p>
                </div>
                <div>
                    <span class="text-gray-500">Importe</span>
                    <p class="font-medium" x-text="formatImporte(hoveredRegion?.total_importe)"></p>
                </div>
            </div>
        </div>

        {{-- Leyenda --}}
        <div>
            <h3 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Leyenda</h3>
            <div class="leyenda-gradient"></div>
            <div class="flex justify-between text-xs text-gray-500 mt-1">
                <span>Menor importe</span>
                <span>Mayor importe</span>
            </div>
        </div>

        {{-- Ranking --}}
        <div class="flex-1 min-h-0">
            <h3 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">
                Ranking por importe
            </h3>
            <ol class="space-y-1.5 text-sm">
                <template x-for="(r, i) in ranking" :key="r.nuts">
                    <li class="flex items-baseline gap-2">
                        <span class="text-gray-400 font-mono text-xs w-5 text-right shrink-0"
                              x-text="(i+1) + '.'"></span>
                        <a x-bind:href="'/administraciones/' + r.ccaa_nuts"
                           class="text-primary hover:underline truncate"
                           x-text="r.nombre"></a>
                        <span class="ml-auto text-gray-500 text-xs whitespace-nowrap"
                              x-text="formatImporte(r.total_importe)"></span>
                    </li>
                </template>
            </ol>
        </div>

        {{-- Loading --}}
        <div x-show="loading" class="flex items-center gap-2 text-sm text-gray-500">
            <svg class="animate-spin h-4 w-4" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" fill="none"/>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
            </svg>
            Cargando datos...
        </div>
    </div>
</div>

@push('scripts')
{{-- 1. Leaflet JS --}}
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>

{{-- 2. Definición del componente (antes de Alpine) --}}
<script>
function mapaApp() {
    const COLORS = ['#FEE2E2','#FECACA','#FCA5A5','#F87171','#EF4444','#DC2626','#B91C1C','#991B1B','#C8102E'];
    const SINGLE_PROVINCE_CCAA = ['ES12','ES13','ES22','ES23','ES30','ES53','ES62','ES63','ES64'];

    return {
        map: null,
        geoLayer: null,
        view: 'ccaa',
        ccaaData: [],
        provinciasData: [],
        ccaaGeoJson: null,
        provinciasGeoJson: null,
        hoveredRegion: null,
        currentCcaa: null,
        loading: false,
        sidebarTitle: 'Contratación pública',
        sidebarSubtitle: 'Todas las comunidades autónomas',

        get ranking() {
            const data = this.view === 'ccaa' ? this.ccaaData : this.provinciasData;
            return [...data]
                .sort((a, b) => b.total_importe - a.total_importe)
                .map(r => ({
                    ...r,
                    ccaa_nuts: this.view === 'ccaa' ? r.nuts : (this.currentCcaa || r.nuts.substring(0, 4))
                }));
        },

        async init() {
            this.map = L.map('mapa', {
                center: [40.0, -3.7],
                zoom: 6,
                zoomControl: true,
                attributionControl: true
            });

            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OSM</a>',
                maxZoom: 12
            }).addTo(this.map);

            setTimeout(() => this.map.invalidateSize(), 100);

            this.loading = true;

            const [ccaaRes, ccaaGeo, provGeo] = await Promise.all([
                fetch('/api/mapa/ccaa').then(r => r.json()),
                fetch('/geojson/spain-ccaa.json').then(r => r.json()),
                fetch('/geojson/spain-provincias.json').then(r => r.json())
            ]);

            this.ccaaData = ccaaRes;
            this.ccaaGeoJson = ccaaGeo;
            this.provinciasGeoJson = provGeo;
            this.loading = false;

            this.showCcaa();
            this.map.invalidateSize();
        },

        getColor(value, max) {
            if (!max || !value) return COLORS[0];
            const ratio = value / max;
            const index = Math.min(Math.floor(ratio * COLORS.length), COLORS.length - 1);
            return COLORS[index];
        },

        showCcaa() {
            this.view = 'ccaa';
            this.currentCcaa = null;
            this.hoveredRegion = null;
            this.sidebarTitle = 'Contratación pública';
            this.sidebarSubtitle = 'Todas las comunidades autónomas';

            if (this.geoLayer) {
                this.map.removeLayer(this.geoLayer);
            }

            const maxImporte = Math.max(...this.ccaaData.map(d => d.total_importe));
            const statsMap = {};
            this.ccaaData.forEach(d => statsMap[d.nuts] = d);

            this.geoLayer = L.geoJSON(this.ccaaGeoJson, {
                style: (feature) => {
                    const nuts = feature.properties.NUTS_ID;
                    const stats = statsMap[nuts];
                    return {
                        fillColor: this.getColor(stats?.total_importe || 0, maxImporte),
                        weight: 1.5,
                        color: '#ffffff',
                        fillOpacity: 0.8
                    };
                },
                onEachFeature: (feature, layer) => {
                    const nuts = feature.properties.NUTS_ID;
                    const stats = statsMap[nuts];

                    layer.on({
                        mouseover: (e) => {
                            e.target.setStyle({ weight: 3, color: '#333333' });
                            e.target.bringToFront();
                            this.hoveredRegion = stats || { nombre: feature.properties.NUTS_NAME, total_contratos: 0, total_importe: 0 };
                        },
                        mouseout: (e) => {
                            this.geoLayer.resetStyle(e.target);
                            this.hoveredRegion = null;
                        },
                        click: () => {
                            if (SINGLE_PROVINCE_CCAA.includes(nuts)) {
                                window.location.href = '/administraciones/' + nuts;
                            } else {
                                this.drillDown(nuts, stats?.nombre || feature.properties.NUTS_NAME);
                            }
                        }
                    });

                    layer.bindTooltip(
                        `<strong>${stats?.nombre || feature.properties.NUTS_NAME}</strong><br>${this.formatNumber(stats?.total_contratos || 0)} contratos`,
                        { sticky: true, className: 'leaflet-tooltip' }
                    );
                }
            }).addTo(this.map);

            this.map.setView([40.0, -3.7], 6);
        },

        async drillDown(ccaaNuts, ccaaNombre) {
            this.loading = true;
            this.currentCcaa = ccaaNuts;
            this.view = 'provincias';
            this.hoveredRegion = null;
            this.sidebarTitle = ccaaNombre;
            this.sidebarSubtitle = 'Provincias';

            const res = await fetch('/api/mapa/provincias?ccaa=' + ccaaNuts);
            this.provinciasData = await res.json();
            this.loading = false;

            if (this.geoLayer) {
                this.map.removeLayer(this.geoLayer);
            }

            const provinciaFeatures = {
                ...this.provinciasGeoJson,
                features: this.provinciasGeoJson.features.filter(
                    f => f.properties.NUTS_ID.startsWith(ccaaNuts)
                )
            };

            const maxImporte = Math.max(...this.provinciasData.map(d => d.total_importe));
            const statsMap = {};
            this.provinciasData.forEach(d => statsMap[d.nuts] = d);

            this.geoLayer = L.geoJSON(provinciaFeatures, {
                style: (feature) => {
                    const nuts = feature.properties.NUTS_ID;
                    const stats = statsMap[nuts];
                    return {
                        fillColor: this.getColor(stats?.total_importe || 0, maxImporte),
                        weight: 1.5,
                        color: '#ffffff',
                        fillOpacity: 0.8
                    };
                },
                onEachFeature: (feature, layer) => {
                    const nuts = feature.properties.NUTS_ID;
                    const stats = statsMap[nuts];

                    layer.on({
                        mouseover: (e) => {
                            e.target.setStyle({ weight: 3, color: '#333333' });
                            e.target.bringToFront();
                            this.hoveredRegion = stats || { nombre: feature.properties.NUTS_NAME, total_contratos: 0, total_importe: 0 };
                        },
                        mouseout: (e) => {
                            this.geoLayer.resetStyle(e.target);
                            this.hoveredRegion = null;
                        },
                        click: () => {
                            window.location.href = '/administraciones/' + ccaaNuts;
                        }
                    });

                    layer.bindTooltip(
                        `<strong>${stats?.nombre || feature.properties.NUTS_NAME}</strong><br>${this.formatNumber(stats?.total_contratos || 0)} contratos`,
                        { sticky: true }
                    );
                }
            }).addTo(this.map);

            const bounds = this.geoLayer.getBounds();
            if (bounds.isValid()) {
                this.map.fitBounds(bounds, { padding: [30, 30] });
            }
        },

        backToCcaa() {
            this.showCcaa();
        },

        formatNumber(n) {
            if (n == null) return '0';
            return new Intl.NumberFormat('es-ES').format(n);
        },

        formatImporte(n) {
            if (n == null) return '0 €';
            if (n >= 1e9) return (n / 1e9).toFixed(1).replace('.', ',') + ' B€';
            if (n >= 1e6) return (n / 1e6).toFixed(1).replace('.', ',') + ' M€';
            if (n >= 1e3) return (n / 1e3).toFixed(0) + ' K€';
            return new Intl.NumberFormat('es-ES', { style: 'currency', currency: 'EUR', maximumFractionDigits: 0 }).format(n);
        }
    };
}
</script>

{{-- 3. Alpine ÚLTIMO, después de Leaflet y mapaApp --}}
<script src="https://unpkg.com/alpinejs@3/dist/cdn.min.js"></script>
@endpush

</x-layouts.app>
