<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Adjudicatario;
use App\Models\Contrato;
use App\Services\FichaEntidadBuilder;
use App\Services\RedRelacionesBuilder;
use App\Services\SerieTemporalBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class EmpresaController extends Controller
{
    /** Primer año con datos en el sistema; acota los años válidos en la URL. */
    private const PRIMER_ANIO = 2008;

    public function __construct(
        private readonly SerieTemporalBuilder $series,
        private readonly FichaEntidadBuilder $fichas,
        private readonly RedRelacionesBuilder $redes,
    ) {}

    public function index(): View
    {
        return view('empresas.index');
    }

    /**
     * Ficha de la empresa centrada en un año: el año en curso por defecto, o el último con
     * actividad si la empresa ya no contrata. El histórico completo vive en su propia
     * página (ver `historico`), no aquí.
     */
    public function show(Adjudicatario $adjudicatario, ?string $year = null): View|RedirectResponse
    {
        $serie = $this->series->paraAdjudicatario($adjudicatario->id);

        $anioActual = (int) date('Y');
        $anio = $this->anioSolicitado($year);

        // Año fuera de rango en la URL: se manda a la ficha en vez de dar un 404.
        if ($year !== null && $anio === null) {
            return redirect()->route('empresas.show', $adjudicatario->nif, 301);
        }

        $anio ??= $serie->anioReferencia($anioActual) ?? $anioActual;

        $ficha = $this->fichas->adjudicatario($adjudicatario->id, $anio);

        return view('empresas.show', [
            'empresa' => $adjudicatario,
            'serie' => $serie,
            'ficha' => $ficha,
            'anio' => $anio,
            'anioFijado' => $year !== null,
            'anioActual' => $anioActual,
            'comparativa' => $serie->comparativaYtd($anio, $anioActual),
            'aliases' => $adjudicatario->aliases()->orderByDesc('veces_visto')->get(),
            'contratos' => $this->contratosDelAnio($adjudicatario, $anio),
            'tipos_contrato' => config('contratacion.tipos_contrato', []),
        ]);
    }

    /**
     * Histórico completo: todos los años, matriz año × mes y récords. Es la página a la que
     * se envía a quien quiere comparar ejercicios, para que la ficha no cargue con ello.
     */
    public function historico(Adjudicatario $adjudicatario): View
    {
        $serie = $this->series->paraAdjudicatario($adjudicatario->id);
        $anioActual = (int) date('Y');

        // Por defecto se superponen los dos años más recientes con datos.
        $aniosComparados = array_slice(array_reverse($serie->aniosConDatos()), 0, 2);
        $fichaHistorica = $this->fichas->adjudicatario($adjudicatario->id);

        return view('empresas.historico', [
            'empresa' => $adjudicatario,
            'serie' => $serie,
            'anioActual' => $anioActual,
            'anios' => $serie->anios(),
            'matriz' => $serie->matrizAnioMes($anioActual),
            'seriesComparadas' => $serie->mesesDe($aniosComparados, $anioActual),
            'ficha' => $fichaHistorica,
            'contratoMayor' => $this->contratoMayor($adjudicatario, $fichaHistorica['kpis']['contrato_mayor']),
            'tipos_contrato' => config('contratacion.tipos_contrato', []),
        ]);
    }

    /**
     * Red de relaciones: con qué organismos contrata y qué otras empresas contratan con
     * esos mismos organismos. Vive aparte de la ficha porque es exploración —y porque el
     * grafo pesa lo suyo— igual que el histórico.
     */
    public function red(Adjudicatario $adjudicatario): View
    {
        $serie = $this->series->paraAdjudicatario($adjudicatario->id);
        $anioActual = (int) date('Y');
        $anioReferencia = $serie->anioReferencia($anioActual) ?? $anioActual;

        return view('empresas.red', [
            'empresa' => $adjudicatario,
            'serie' => $serie,
            'anioActual' => $anioActual,
            'red' => $this->redes->paraAdjudicatario($adjudicatario->id, $anioReferencia),
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
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator<int, Contrato>
     */
    private function contratosDelAnio(Adjudicatario $adjudicatario, int $anio)
    {
        return Contrato::query()
            ->forAdjudicatario($adjudicatario->id)
            ->year($anio)
            ->with('organismo:id,nombre,nif')
            ->orderByDesc('fecha_publicacion')
            ->paginate(10)
            ->withQueryString();
    }

    /**
     * Contrato de mayor importe. Se busca por igualdad contra el máximo que ya calculó la
     * ficha, en vez de con `orderByDesc('importe_adjudicacion')`: ese orden hace que
     * PostgreSQL recorra el índice global de importes de mayor a menor hasta dar con una
     * fila de la entidad — 6,9 s en el organismo mayor. Por igualdad son milisegundos.
     */
    private function contratoMayor(Adjudicatario $adjudicatario, ?float $importeMaximo): ?Contrato
    {
        if ($importeMaximo === null) {
            return null;
        }

        return Contrato::query()
            ->forAdjudicatario($adjudicatario->id)
            ->where('importe_adjudicacion', $importeMaximo)
            ->with('organismo:id,nombre,nif')
            ->first();
    }
}
