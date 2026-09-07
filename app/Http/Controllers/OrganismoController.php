<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Contrato;
use App\Models\Organismo;
use App\Services\FichaEntidadBuilder;
use App\Services\RedRelacionesBuilder;
use App\Services\SerieTemporalBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class OrganismoController extends Controller
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
        return view('organismos.index');
    }

    /**
     * Ficha del organismo centrada en un año: el año en curso por defecto, o el último con
     * actividad. El histórico completo vive en `historico`.
     */
    public function show(Organismo $organismo, ?string $year = null): View|RedirectResponse
    {
        $serie = $this->series->paraOrganismo($organismo->id);

        $anioActual = (int) date('Y');
        $anio = $this->anioSolicitado($year);

        if ($year !== null && $anio === null) {
            return redirect()->route('organismos.show', $organismo->nif, 301);
        }

        $anio ??= $serie->anioReferencia($anioActual) ?? $anioActual;

        return view('organismos.show', [
            'organismo' => $organismo,
            'serie' => $serie,
            'ficha' => $this->fichas->organismo($organismo->id, $anio),
            'anio' => $anio,
            'anioFijado' => $year !== null,
            'anioActual' => $anioActual,
            'comparativa' => $serie->comparativaYtd($anio, $anioActual),
            'ultimosContratos' => $this->contratosDelAnio($organismo, $anio),
            'tipos_contrato' => config('contratacion.tipos_contrato', []),
        ]);
    }

    /** Histórico completo: todos los años, matriz año × mes y récords. */
    public function historico(Organismo $organismo): View
    {
        $serie = $this->series->paraOrganismo($organismo->id);
        $anioActual = (int) date('Y');

        $aniosComparados = array_slice(array_reverse($serie->aniosConDatos()), 0, 2);
        $fichaHistorica = $this->fichas->organismo($organismo->id);

        return view('organismos.historico', [
            'organismo' => $organismo,
            'serie' => $serie,
            'anioActual' => $anioActual,
            'anios' => $serie->anios(),
            'matriz' => $serie->matrizAnioMes($anioActual),
            'seriesComparadas' => $serie->mesesDe($aniosComparados, $anioActual),
            'ficha' => $fichaHistorica,
            'contratoMayor' => $this->contratoMayor($organismo, $fichaHistorica['kpis']['contrato_mayor']),
            'tipos_contrato' => config('contratacion.tipos_contrato', []),
        ]);
    }

    /**
     * Red de relaciones: a qué adjudicatarios contrata y qué otros organismos comparten
     * esos proveedores.
     */
    public function red(Organismo $organismo): View
    {
        $serie = $this->series->paraOrganismo($organismo->id);
        $anioActual = (int) date('Y');
        $anioReferencia = $serie->anioReferencia($anioActual) ?? $anioActual;

        return view('organismos.red', [
            'organismo' => $organismo,
            'serie' => $serie,
            'anioActual' => $anioActual,
            'red' => $this->redes->paraOrganismo($organismo->id, $anioReferencia),
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
    private function contratosDelAnio(Organismo $organismo, int $anio)
    {
        return Contrato::query()
            ->forOrganismo($organismo->id)
            ->year($anio)
            ->with('adjudicatario:id,nombre,nif')
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
    private function contratoMayor(Organismo $organismo, ?float $importeMaximo): ?Contrato
    {
        if ($importeMaximo === null) {
            return null;
        }

        return Contrato::query()
            ->forOrganismo($organismo->id)
            ->where('importe_adjudicacion', $importeMaximo)
            ->with('adjudicatario:id,nombre,nif')
            ->first();
    }
}
