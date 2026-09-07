<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ComunidadAutonoma;
use App\Models\Contrato;
use App\Models\Organismo;
use App\Services\FichaEntidadBuilder;
use App\Services\SerieTemporalBuilder;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AdministracionController extends Controller
{
    /** Primer año con datos en el sistema; acota los años válidos en la URL. */
    private const PRIMER_ANIO = 2008;

    public function __construct(
        private readonly SerieTemporalBuilder $series,
        private readonly FichaEntidadBuilder $fichas,
    ) {}

    public function index(): View
    {
        // Leer stats pre-computadas (generadas por stats:recalculate --entity=mapa)
        $statsMap = [];
        if (Storage::exists('mapa-stats/ccaa.json')) {
            $statsData = json_decode(Storage::get('mapa-stats/ccaa.json'), true);
            foreach ($statsData as $row) {
                $statsMap[$row['nuts']] = $row;
            }
        }

        $ccaa = ComunidadAutonoma::orderBy('nombre')
            ->get()
            ->map(function (ComunidadAutonoma $ca) use ($statsMap) {
                $stats = $statsMap[$ca->nuts] ?? null;
                $ca->stats_contratos = $stats ? (int) $stats['total_contratos'] : 0;
                $ca->stats_importe = $stats ? (float) $stats['total_importe'] : 0.0;
                $ca->stats_per_capita = $stats['gasto_per_capita'] ?? null;
                $ca->stats_organismos = 0;

                // Organismos count: usar pre-computado si existe
                $orgFile = "mapa-stats/admin-{$ca->nuts}.json";
                if (Storage::exists($orgFile)) {
                    $adminData = json_decode(Storage::get($orgFile), true);
                    $ca->stats_organismos = $adminData['total_organismos'] ?? 0;
                }

                return $ca;
            })
            ->sortByDesc('stats_importe')
            ->values();

        return view('administraciones.index', [
            'ccaa' => $ccaa,
        ]);
    }

    /**
     * Ficha de la comunidad centrada en un año, con comparativa contra el anterior. Los
     * totales acumulados de todos los ejercicios viven en `historico`.
     */
    public function show(ComunidadAutonoma $comunidad, ?string $year = null): View|RedirectResponse
    {
        $serie = $this->series->paraNuts($comunidad->nuts);

        $anioActual = (int) date('Y');
        $anio = $this->anioSolicitado($year);

        if ($year !== null && $anio === null) {
            return redirect()->route('administraciones.show', $comunidad->nuts, 301);
        }

        $anio ??= $serie->anioReferencia($anioActual) ?? $anioActual;

        $ficha = $this->fichas->territorio($comunidad->nuts, $anio);
        $cached = $this->statsPrecomputadas($comunidad);

        return view('administraciones.show', [
            'comunidad' => $comunidad,
            'serie' => $serie,
            'ficha' => $ficha,
            'anio' => $anio,
            'anioFijado' => $year !== null,
            'anioActual' => $anioActual,
            'comparativa' => $serie->comparativaYtd($anio, $anioActual),
            'total_organismos' => $cached['total_organismos'],
            'total_adjudicatarios' => $cached['total_adjudicatarios'],
            'stats_precomputadas' => $cached['disponibles'],
            'ultimos_contratos' => $this->contratosDelAnio($comunidad, $anio),
            'tipos_contrato' => config('contratacion.tipos_contrato', []),
        ]);
    }

    /** Histórico completo de la comunidad: todos los años, matriz año × mes y récords. */
    public function historico(ComunidadAutonoma $comunidad): View
    {
        $serie = $this->series->paraNuts($comunidad->nuts);
        $anioActual = (int) date('Y');

        $aniosComparados = array_slice(array_reverse($serie->aniosConDatos()), 0, 2);
        $cached = $this->statsPrecomputadas($comunidad);

        return view('administraciones.historico', [
            'comunidad' => $comunidad,
            'serie' => $serie,
            'anioActual' => $anioActual,
            'anios' => $serie->anios(),
            'matriz' => $serie->matrizAnioMes($anioActual),
            'seriesComparadas' => $serie->mesesDe($aniosComparados, $anioActual),
            'topOrganismos' => $this->topOrganismos($comunidad, $cached['top_organismos_ids']),
            'total_organismos' => $cached['total_organismos'],
            'total_adjudicatarios' => $cached['total_adjudicatarios'],
        ]);
    }

    /** Año pedido en la URL, o null si no es un año plausible del sistema. */
    private function anioSolicitado(?string $year): ?int
    {
        if ($year === null || ! ctype_digit($year)) {
            return null;
        }

        $anio = (int) $year;

        return ($anio >= self::PRIMER_ANIO && $anio <= (int) date('Y') + 1) ? $anio : null;
    }

    /**
     * Recuentos de organismos y adjudicatarios de la comunidad. Vienen de
     * `mapa-stats/admin-{nuts}.json`; contarlos en vivo obliga a un anti-join sobre las
     * 618.958 empresas y no es viable en una petición web, así que si el fichero no está
     * se devuelve `disponibles: false` y la vista lo dice en vez de inventar un cero.
     *
     * @return array{total_organismos: int|null, total_adjudicatarios: int|null, top_organismos_ids: list<int>, disponibles: bool}
     */
    private function statsPrecomputadas(ComunidadAutonoma $comunidad): array
    {
        $fichero = "mapa-stats/admin-{$comunidad->nuts}.json";

        if (! Storage::exists($fichero)) {
            return [
                'total_organismos' => null,
                'total_adjudicatarios' => null,
                'top_organismos_ids' => [],
                'disponibles' => false,
            ];
        }

        $datos = json_decode((string) Storage::get($fichero), true);

        return [
            'total_organismos' => isset($datos['total_organismos']) ? (int) $datos['total_organismos'] : null,
            'total_adjudicatarios' => isset($datos['total_adjudicatarios']) ? (int) $datos['total_adjudicatarios'] : null,
            'top_organismos_ids' => $datos['top_organismos_ids'] ?? [],
            'disponibles' => true,
        ];
    }

    /**
     * @param  list<int>  $ids
     * @return \Illuminate\Database\Eloquent\Collection<int, Organismo>
     */
    private function topOrganismos(ComunidadAutonoma $comunidad, array $ids)
    {
        if ($ids === []) {
            // Sin precálculo: al menos los organismos con más volumen de la comunidad.
            return Organismo::query()
                ->where('total_contratos', '>', 0)
                ->whereExists(fn ($q) => $q->select(DB::raw(1))
                    ->from('contratos')
                    ->whereColumn('contratos.organismo_id', 'organismos.id')
                    ->where('contratos.nuts', 'LIKE', "{$comunidad->nuts}%"))
                ->orderByDesc('total_importe')
                ->limit(20)
                ->get();
        }

        return Organismo::whereIn('id', $ids)->orderByDesc('total_importe')->get();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Contrato>
     */
    private function contratosDelAnio(ComunidadAutonoma $comunidad, int $anio)
    {
        return Contrato::query()
            ->with(['organismo:id,nombre,nif', 'adjudicatario:id,nombre,nif'])
            ->ccaa($comunidad->nuts)
            ->year($anio)
            ->orderByDesc('fecha_publicacion')
            ->limit(10)
            ->get();
    }
}
