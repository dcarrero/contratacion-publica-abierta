@props([
    'id',
    'series',               // [anio => [ ['mes','etiqueta','num','importe','parcial','futuro'], ... ]]
    'height' => '300px',
    'metrica' => 'importe', // métrica inicial
])
@php
    // El año mayor es el protagonista; los demás quedan como referencia.
    $anios = collect($series)->keys()->sortDesc()->values()->all();
@endphp
{{-- Comparativa mes a mes entre años. Los meses futuros van como null para que la línea
     se corte en el mes en curso en vez de desplomarse hasta cero. --}}
<div data-grafico-mensual="{{ $id }}">
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
        <div class="flex gap-1 text-xs" role="group" aria-label="Métrica del gráfico">
            <button type="button" data-metrica="importe"
                    class="px-3 py-1.5 rounded-lg font-medium transition {{ $metrica === 'importe' ? 'bg-primary text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}">
                Importe
            </button>
            <button type="button" data-metrica="num"
                    class="px-3 py-1.5 rounded-lg font-medium transition {{ $metrica === 'num' ? 'bg-primary text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}">
                Nº de contratos
            </button>
        </div>
        <p class="text-xs text-gray-400">El mes en curso es un dato parcial</p>
    </div>

    <x-chart :id="$id" :height="$height" />
</div>

<x-serie.chartjs />

@once
@push('scripts')
<script>
window.graficoMensual = function (canvasId, series, anios, metricaInicial) {
    const canvas = document.getElementById(canvasId);
    if (!canvas || typeof Chart === 'undefined') return;

    const contenedor = document.querySelector('[data-grafico-mensual="' + canvasId + '"]');
    const etiquetas = (series[anios[0]] || []).map(m => m.etiqueta);
    const colores = ['#dc2626', '#94a3b8', '#2563eb', '#16a34a', '#d97706'];

    const fmtImporte = window.formatImporteCorto;

    // Chart.js invoca los callbacks de ejes y tooltips durante la construcción, antes de
    // que la const `chart` exista: la métrica activa vive en el closure, no en el objeto.
    let metricaActual = metricaInicial;

    // Un mes futuro no es un cero: se deja como hueco.
    const datos = (anio, metrica) => series[anio].map(m => m.futuro ? null : m[metrica]);

    const construirDatasets = metrica => anios.map((anio, i) => ({
        label: String(anio),
        data: datos(anio, metrica),
        backgroundColor: i === 0 ? colores[0] : 'rgba(148,163,184,0.55)',
        borderColor: colores[i % colores.length],
        borderWidth: i === 0 ? 0 : 1,
        borderRadius: 3,
        // El año principal en barras; los de referencia, en barra más tenue.
        order: i,
    }));

    const chart = new Chart(canvas, {
        type: 'bar',
        data: { labels: etiquetas, datasets: construirDatasets(metricaInicial) },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } },
                tooltip: {
                    callbacks: {
                        label: ctx => {
                            const punto = series[ctx.dataset.label][ctx.dataIndex];
                            const valor = metricaActual === 'importe'
                                ? fmtImporte(ctx.parsed.y)
                                : ctx.parsed.y.toLocaleString('es-ES') + ' contratos';
                            return ctx.dataset.label + ': ' + valor + (punto && punto.parcial ? ' (mes en curso)' : '');
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: v => metricaActual === 'importe'
                            ? fmtImporte(v)
                            : v.toLocaleString('es-ES')
                    }
                }
            }
        }
    });

    if (contenedor) {
        contenedor.querySelectorAll('[data-metrica]').forEach(boton => {
            boton.addEventListener('click', () => {
                const metrica = boton.dataset.metrica;
                if (metrica === metricaActual) return;

                metricaActual = metrica;
                chart.data.datasets = construirDatasets(metrica);
                chart.update();

                contenedor.querySelectorAll('[data-metrica]').forEach(otro => {
                    const activo = otro === boton;
                    otro.classList.toggle('bg-primary', activo);
                    otro.classList.toggle('text-white', activo);
                    otro.classList.toggle('bg-gray-100', !activo);
                    otro.classList.toggle('text-gray-600', !activo);
                    otro.classList.toggle('hover:bg-gray-200', !activo);
                });
            });
        });
    }

    return chart;
};
</script>
@endpush
@endonce

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    window.graficoMensual(
        @json($id),
        @json($series),
        @json($anios),
        @json($metrica)
    );
});
</script>
@endpush
