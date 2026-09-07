<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Adjudicatario;
use App\Models\Contrato;
use App\Models\Organismo;
use App\Services\SerieTemporalBuilder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(private readonly SerieTemporalBuilder $series) {}

    public function __invoke(): View
    {
        $serie = $this->series->nacional();
        $anioActual = (int) date('Y');
        $anio = $serie->anioReferencia($anioActual) ?? $anioActual;

        $charts = $this->charts();

        // Totales acumulados: contexto, ya no el titular de la portada.
        $totalesHistoricos = Cache::remember('dashboard_totales_historicos', 3600, fn () => [
            'contratos' => (int) Organismo::sum('total_contratos'),
            'volumen' => (float) Organismo::sum('total_importe'),
            'organismos' => Organismo::count(),
            'adjudicatarios' => Adjudicatario::count(),
        ]);

        $ultimosContratos = Contrato::with(['organismo:id,nombre,nif', 'adjudicatario:id,nombre,nif'])
            ->whereNotNull('fecha_publicacion')
            ->orderByDesc('fecha_publicacion')
            ->limit(20)
            ->get();

        return view('dashboard', [
            'serie' => $serie,
            'anio' => $anio,
            'anioActual' => $anioActual,
            'comparativa' => $serie->comparativaYtd($anio, $anioActual),
            'totalAnio' => $serie->totalAnio($anio),
            'seriesGrafico' => $serie->mesesDe(
                array_values(array_filter([$anio, $anio - 1], fn ($a) => $serie->tieneDatosEn($a))),
                $anioActual
            ),
            'totalesHistoricos' => $totalesHistoricos,
            // Rankings del año en curso si el recálculo los dejó escritos; si no, los
            // acumulados de siempre, señalados como tales para no confundirlos.
            'rankingsAnio' => $charts['anio_actual'] ?? null,
            'topEmpresas' => $this->topEmpresasHistorico(),
            'topOrganismos' => $this->topOrganismosHistorico(),
            'ultimosContratos' => $ultimosContratos,
            'charts' => $charts,
        ]);
    }

    /** Histórico nacional: todos los años, matriz año × mes y acceso a los de cada CCAA. */
    public function historico(): View
    {
        $serie = $this->series->nacional();
        $anioActual = (int) date('Y');
        $aniosComparados = array_slice(array_reverse($serie->aniosConDatos()), 0, 2);

        $charts = $this->charts();

        return view('historico', [
            'serie' => $serie,
            'anioActual' => $anioActual,
            'anios' => $serie->anios(),
            'matriz' => $serie->matrizAnioMes($anioActual),
            'seriesComparadas' => $serie->mesesDe($aniosComparados, $anioActual),
            'distribucionCcaa' => $charts['distribucion_ccaa'] ?? [],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function charts(): array
    {
        if (! Storage::exists('mapa-stats/charts.json')) {
            return [];
        }

        return json_decode((string) Storage::get('mapa-stats/charts.json'), true) ?? [];
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Adjudicatario>
     */
    private function topEmpresasHistorico()
    {
        return Cache::remember(
            'dashboard_top_empresas',
            3600,
            fn () => Adjudicatario::orderByDesc('total_importe')
                ->limit(10)
                ->get(['nombre', 'nif', 'total_contratos', 'total_importe'])
        );
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Organismo>
     */
    private function topOrganismosHistorico()
    {
        return Cache::remember(
            'dashboard_top_organismos',
            3600,
            fn () => Organismo::orderByDesc('total_importe')
                ->limit(10)
                ->get(['nombre', 'nif', 'total_contratos', 'total_importe'])
        );
    }
}
