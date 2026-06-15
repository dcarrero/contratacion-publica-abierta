<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Anomalia;
use App\Models\ComunidadAutonoma;
use App\Models\Provincia;
use App\Support\SqlDialect;
use Illuminate\Support\Facades\DB;

class InformeDataBuilder
{
    private string $yearExpr;

    private string $monthExpr;

    public function __construct()
    {
        $this->yearExpr = SqlDialect::year('fecha_publicacion');
        $this->monthExpr = SqlDialect::yearMonth('fecha_publicacion');
    }

    public function buildCcaa(string $nuts2): array
    {
        $ccaa = ComunidadAutonoma::where('nuts', $nuts2)->firstOrFail();

        return array_merge([
            'nuts' => $nuts2,
            'nombre' => $ccaa->nombre,
            'generado_at' => now()->toIso8601String(),
        ], $this->buildGeoReport("{$nuts2}%", $ccaa->poblacion));
    }

    /**
     * Radiografía de una provincia (A4): mismo informe geográfico que CCAA pero filtrado por
     * el NUTS3 de la provincia y con su población. Contenido público de transparencia.
     */
    public function buildProvincia(Provincia $provincia, ?int $year = null): array
    {
        $nutsLike = "{$provincia->nuts}%";

        // Sin anomalías: la radiografía no las muestra y su cálculo (EXISTS anidado sobre anomalías)
        // es el paso más caro (>10s). Si en el futuro se muestran, optimizar buildAnomaliasResumen.
        $report = array_merge([
            'nuts' => $provincia->nuts,
            'nombre' => $provincia->nombre,
            'comunidad' => $provincia->comunidadAutonoma?->nombre,
            'generado_at' => now()->toIso8601String(),
            'year' => $year,
            'anios_disponibles' => $this->aniosDisponibles($nutsLike),
        ], $this->buildGeoReport($nutsLike, $provincia->poblacion, includeAnomalias: false, year: $year));

        // Comparación con el año anterior (YoY) cuando se filtra por un año.
        if ($year !== null) {
            $report['comparativa'] = $this->comparativaGeoYear($nutsLike, $provincia->poblacion, $year);
        }

        return $report;
    }

    /**
     * Cuerpo común del informe geográfico (CCAA o provincia): KPIs, per cápita, evolución,
     * distribución, top CPV/adjudicatarios/organismos y anomalías, filtrando por $nutsLike.
     */
    private function buildGeoReport(string $nutsLike, ?int $poblacion, bool $includeAnomalias = true, ?int $year = null): array
    {
        [$nLow, $nHigh] = $this->nutsBounds($nutsLike);

        // Filtro de año opcional (se aplica a KPIs/tops, no a la evolución que es la serie completa).
        $yearFilter = fn ($q) => $q->whereNotNull('fecha_publicacion')
            ->whereRaw("{$this->yearExpr} = ?", [(string) $year]);

        // KPIs
        $kpis = DB::table('contratos')
            ->where('nuts', '>=', $nLow)->where('nuts', '<', $nHigh)
            ->when($year !== null, $yearFilter)
            ->selectRaw('COUNT(*) as total_contratos')
            ->selectRaw('COALESCE(SUM(importe_adjudicacion), 0) as total_importe')
            ->selectRaw(SqlDialect::sumBool('es_menor').' as total_menores')
            ->first();

        $totalContratos = (int) $kpis->total_contratos;
        $totalImporte = (float) $kpis->total_importe;

        // Conteos distintos directamente desde los contratos de la zona (un solo scan por rango de
        // índice sobre nuts), en vez de subconsultas correlacionadas sobre las tablas completas.
        $distintos = DB::table('contratos')
            ->where('nuts', '>=', $nLow)->where('nuts', '<', $nHigh)
            ->when($year !== null, $yearFilter)
            ->selectRaw('COUNT(DISTINCT organismo_id) as organismos')
            ->selectRaw('COUNT(DISTINCT adjudicatario_id) as adjudicatarios')
            ->first();

        $totalOrganismos = (int) $distintos->organismos;
        $totalAdjudicatarios = (int) $distintos->adjudicatarios;

        $pctMenores = $totalContratos > 0
            ? round((int) $kpis->total_menores / $totalContratos * 100, 1)
            : 0;
        $importeMedio = $totalContratos > 0
            ? round($totalImporte / $totalContratos, 2)
            : 0;

        // Evolución anual
        $evolucionAnual = DB::table('contratos')
            ->where('nuts', '>=', $nLow)->where('nuts', '<', $nHigh)
            ->whereNotNull('fecha_publicacion')
            ->selectRaw("{$this->yearExpr} as year, COUNT(*) as num_contratos, COALESCE(SUM(importe_adjudicacion), 0) as total_importe")
            ->groupByRaw($this->yearExpr)
            ->orderBy('year')
            ->get()
            ->map(fn ($r) => [
                'year' => (int) $r->year,
                'num_contratos' => (int) $r->num_contratos,
                'total_importe' => (float) $r->total_importe,
            ])
            ->values()
            ->all();

        // Distribución por tipo
        $distribucionTipo = $this->buildDistribucionTipo($nutsLike, $year);

        // Top CPV
        $topCpv = $this->buildTopCpv($nutsLike, config('contratacion.informes.top_cpv', 10), $year);

        // Top adjudicatarios
        $topAdj = config('contratacion.informes.top_adjudicatarios', 20);
        $topAdjudicatarios = DB::table('contratos')
            ->join('adjudicatarios', 'contratos.adjudicatario_id', '=', 'adjudicatarios.id')
            ->where('contratos.nuts', '>=', $nLow)->where('contratos.nuts', '<', $nHigh)
            ->when($year !== null, $yearFilter)
            ->select('adjudicatarios.nombre', 'adjudicatarios.nif')
            ->selectRaw('COUNT(*) as total_contratos')
            ->selectRaw('COALESCE(SUM(contratos.importe_adjudicacion), 0) as total_importe')
            ->groupBy('adjudicatarios.id', 'adjudicatarios.nombre', 'adjudicatarios.nif')
            ->orderByDesc('total_importe')
            ->limit($topAdj)
            ->get()
            ->map(fn ($r) => [
                'nombre' => $r->nombre,
                'nif' => $r->nif,
                'total_contratos' => (int) $r->total_contratos,
                'total_importe' => (float) $r->total_importe,
            ])
            ->values()
            ->all();

        // Top organismos
        $topOrg = config('contratacion.informes.top_organismos', 20);
        $topOrganismos = DB::table('contratos')
            ->join('organismos', 'contratos.organismo_id', '=', 'organismos.id')
            ->where('contratos.nuts', '>=', $nLow)->where('contratos.nuts', '<', $nHigh)
            ->when($year !== null, $yearFilter)
            ->select('organismos.nombre', 'organismos.nif')
            ->selectRaw('COUNT(*) as total_contratos')
            ->selectRaw('COALESCE(SUM(contratos.importe_adjudicacion), 0) as total_importe')
            ->groupBy('organismos.id', 'organismos.nombre', 'organismos.nif')
            ->orderByDesc('total_importe')
            ->limit($topOrg)
            ->get()
            ->map(fn ($r) => [
                'nombre' => $r->nombre,
                'nif' => $r->nif,
                'total_contratos' => (int) $r->total_contratos,
                'total_importe' => (float) $r->total_importe,
            ])
            ->values()
            ->all();

        // Anomalías resumen (omitible: es el paso más caro y no todas las vistas lo usan)
        $anomaliasResumen = $includeAnomalias
            ? $this->buildAnomaliasResumen($nutsLike)
            : ['total' => 0, 'fraccionamiento' => 0, 'concentracion' => 0, 'pico_temporal' => 0];

        return [
            'kpis' => [
                'total_contratos' => $totalContratos,
                'total_importe' => $totalImporte,
                'total_organismos' => $totalOrganismos,
                'total_adjudicatarios' => $totalAdjudicatarios,
                'pct_menores' => $pctMenores,
                'importe_medio' => $importeMedio,
                'poblacion' => $poblacion,
                'gasto_per_capita' => ($poblacion && $poblacion > 0)
                    ? round($totalImporte / $poblacion, 2)
                    : null,
            ],
            'evolucion_anual' => $evolucionAnual,
            'distribucion_tipo' => $distribucionTipo,
            'top_cpv' => $topCpv,
            'top_adjudicatarios' => $topAdjudicatarios,
            'top_organismos' => $topOrganismos,
            'anomalias_resumen' => $anomaliasResumen,
        ];
    }

    public function buildAnual(int $year): array
    {
        $yearStr = (string) $year;

        // KPIs del año
        $kpis = DB::table('contratos')
            ->whereNotNull('fecha_publicacion')
            ->whereRaw("{$this->yearExpr} = ?", [$yearStr])
            ->selectRaw('COUNT(*) as total_contratos')
            ->selectRaw('COALESCE(SUM(importe_adjudicacion), 0) as total_importe')
            ->selectRaw(SqlDialect::sumBool('es_menor').' as total_menores')
            ->first();

        $totalContratos = (int) $kpis->total_contratos;
        $totalImporte = (float) $kpis->total_importe;

        $totalOrganismos = (int) DB::table('organismos')
            ->whereExists(function ($q) use ($yearStr) {
                $q->select(DB::raw(1))->from('contratos')
                    ->whereColumn('contratos.organismo_id', 'organismos.id')
                    ->whereNotNull('contratos.fecha_publicacion')
                    ->whereRaw("{$this->yearExpr} = ?", [$yearStr]);
            })->count();

        $totalAdjudicatarios = (int) DB::table('adjudicatarios')
            ->whereExists(function ($q) use ($yearStr) {
                $q->select(DB::raw(1))->from('contratos')
                    ->whereColumn('contratos.adjudicatario_id', 'adjudicatarios.id')
                    ->whereNotNull('contratos.fecha_publicacion')
                    ->whereRaw("{$this->yearExpr} = ?", [$yearStr]);
            })->count();

        $pctMenores = $totalContratos > 0
            ? round((int) $kpis->total_menores / $totalContratos * 100, 1)
            : 0;
        $importeMedio = $totalContratos > 0
            ? round($totalImporte / $totalContratos, 2)
            : 0;

        // Evolución mensual
        $evolucionMensual = DB::table('contratos')
            ->whereNotNull('fecha_publicacion')
            ->whereRaw("{$this->yearExpr} = ?", [$yearStr])
            ->selectRaw("{$this->monthExpr} as mes, COUNT(*) as num_contratos, COALESCE(SUM(importe_adjudicacion), 0) as total_importe")
            ->groupByRaw($this->monthExpr)
            ->orderBy('mes')
            ->get()
            ->map(fn ($r) => [
                'mes' => $r->mes,
                'num_contratos' => (int) $r->num_contratos,
                'total_importe' => (float) $r->total_importe,
            ])
            ->values()
            ->all();

        // Distribución tipo (año)
        $distribucionTipo = $this->buildDistribucionTipoAnual($yearStr);

        // Comparativa CCAA
        $comparativaCcaa = ComunidadAutonoma::orderBy('nombre')->get()->map(function (ComunidadAutonoma $ca) use ($yearStr) {
            $stats = DB::table('contratos')
                ->where('nuts', 'LIKE', "{$ca->nuts}%")
                ->whereNotNull('fecha_publicacion')
                ->whereRaw("{$this->yearExpr} = ?", [$yearStr])
                ->selectRaw('COUNT(*) as total_contratos')
                ->selectRaw('COALESCE(SUM(importe_adjudicacion), 0) as total_importe')
                ->selectRaw(SqlDialect::sumBool('es_menor').' as total_menores')
                ->first();

            $tc = (int) $stats->total_contratos;

            return [
                'nombre' => $ca->nombre,
                'nuts' => $ca->nuts,
                'total_contratos' => $tc,
                'total_importe' => (float) $stats->total_importe,
                'pct_menores' => $tc > 0 ? round((int) $stats->total_menores / $tc * 100, 1) : 0,
            ];
        })
            ->filter(fn ($c) => $c['total_contratos'] > 0)
            ->sortByDesc('total_importe')
            ->values()
            ->all();

        // Top adjudicatarios del año
        $topAdj = config('contratacion.informes.top_adjudicatarios', 20);
        $topAdjudicatarios = DB::table('contratos')
            ->join('adjudicatarios', 'contratos.adjudicatario_id', '=', 'adjudicatarios.id')
            ->whereNotNull('contratos.fecha_publicacion')
            ->whereRaw("{$this->yearExpr} = ?", [$yearStr])
            ->select('adjudicatarios.nombre', 'adjudicatarios.nif')
            ->selectRaw('COUNT(*) as total_contratos')
            ->selectRaw('COALESCE(SUM(contratos.importe_adjudicacion), 0) as total_importe')
            ->groupBy('adjudicatarios.id', 'adjudicatarios.nombre', 'adjudicatarios.nif')
            ->orderByDesc('total_importe')
            ->limit($topAdj)
            ->get()
            ->map(fn ($r) => [
                'nombre' => $r->nombre,
                'nif' => $r->nif,
                'total_contratos' => (int) $r->total_contratos,
                'total_importe' => (float) $r->total_importe,
            ])
            ->values()
            ->all();

        // Top CPV del año
        $topCpvAnual = $this->buildTopCpvAnual($yearStr, config('contratacion.informes.top_cpv', 10));

        // Anomalías resumen del año
        $anomaliasResumen = [
            'total' => (int) Anomalia::whereRaw("{$this->yearExprAnomalia()} = ?", [$yearStr])->count(),
            'fraccionamiento' => (int) Anomalia::where('tipo', 'fraccionamiento')->whereRaw("{$this->yearExprAnomalia()} = ?", [$yearStr])->count(),
            'concentracion' => (int) Anomalia::where('tipo', 'concentracion')->whereRaw("{$this->yearExprAnomalia()} = ?", [$yearStr])->count(),
            'pico_temporal' => (int) Anomalia::where('tipo', 'pico_temporal')->whereRaw("{$this->yearExprAnomalia()} = ?", [$yearStr])->count(),
        ];

        return [
            'year' => $year,
            'generado_at' => now()->toIso8601String(),
            'kpis' => [
                'total_contratos' => $totalContratos,
                'total_importe' => $totalImporte,
                'total_organismos' => $totalOrganismos,
                'total_adjudicatarios' => $totalAdjudicatarios,
                'pct_menores' => $pctMenores,
                'importe_medio' => $importeMedio,
            ],
            'evolucion_mensual' => $evolucionMensual,
            'distribucion_tipo' => $distribucionTipo,
            'comparativa_ccaa' => $comparativaCcaa,
            'top_adjudicatarios' => $topAdjudicatarios,
            'top_cpv' => $topCpvAnual,
            'anomalias_resumen' => $anomaliasResumen,
        ];
    }

    private function buildDistribucionTipo(string $nutsLike, ?int $year = null): array
    {
        $tipos = config('contratacion.tipos_contrato', []);

        [$nLow, $nHigh] = $this->nutsBounds($nutsLike);
        $rawTipos = DB::table('contratos')
            ->where('nuts', '>=', $nLow)->where('nuts', '<', $nHigh)
            ->when($year !== null, fn ($q) => $q->whereNotNull('fecha_publicacion')->whereRaw("{$this->yearExpr} = ?", [(string) $year]))
            ->selectRaw('tipo_contrato, COUNT(*) as num_contratos, COALESCE(SUM(importe_adjudicacion), 0) as total_importe')
            ->groupBy('tipo_contrato')
            ->get();

        return $this->normalizeTipos($rawTipos, $tipos);
    }

    private function buildDistribucionTipoAnual(string $yearStr): array
    {
        $tipos = config('contratacion.tipos_contrato', []);

        $rawTipos = DB::table('contratos')
            ->whereNotNull('fecha_publicacion')
            ->whereRaw("{$this->yearExpr} = ?", [$yearStr])
            ->selectRaw('tipo_contrato, COUNT(*) as num_contratos, COALESCE(SUM(importe_adjudicacion), 0) as total_importe')
            ->groupBy('tipo_contrato')
            ->get();

        return $this->normalizeTipos($rawTipos, $tipos);
    }

    private function normalizeTipos($rawTipos, array $tipos): array
    {
        $tipoNorm = [
            'servicios' => 2, 'serveis' => 2, 'servicio' => 2,
            'suministros' => 1, 'subministraments' => 1, 'suministro' => 1,
            'obras' => 3, 'obres' => 3,
            'patrimonial' => 7, 'patrimoniales' => 7,
        ];

        $agrupado = [];
        foreach ($rawTipos as $r) {
            $val = trim((string) ($r->tipo_contrato ?? ''));
            if ($val !== '' && is_numeric($val) && isset($tipos[(int) $val])) {
                $key = (int) $val;
            } elseif (isset($tipoNorm[mb_strtolower($val)])) {
                $key = $tipoNorm[mb_strtolower($val)];
            } elseif (str_contains(mb_strtolower($val), 'servicio') || str_contains(mb_strtolower($val), 'serveis')) {
                $key = 2;
            } elseif (str_contains(mb_strtolower($val), 'suministr') || str_contains(mb_strtolower($val), 'subministra')) {
                $key = 1;
            } elseif (str_contains(mb_strtolower($val), 'obra') || str_contains(mb_strtolower($val), 'obres')) {
                $key = 3;
            } elseif (str_contains(mb_strtolower($val), 'concesi') || str_contains(mb_strtolower($val), 'concessi')) {
                $key = 31;
            } elseif (str_contains(mb_strtolower($val), 'patrimonial')) {
                $key = 7;
            } elseif (str_contains(mb_strtolower($val), 'administrativ')) {
                $key = 8;
            } else {
                $key = 999;
            }

            if (! isset($agrupado[$key])) {
                $agrupado[$key] = ['num_contratos' => 0, 'total_importe' => 0.0];
            }
            $agrupado[$key]['num_contratos'] += (int) $r->num_contratos;
            $agrupado[$key]['total_importe'] += (float) $r->total_importe;
        }

        return collect($agrupado)
            ->map(fn ($v, $k) => [
                'tipo_contrato' => $k,
                'label' => $tipos[$k] ?? 'Otros',
                'num_contratos' => $v['num_contratos'],
                'total_importe' => $v['total_importe'],
            ])
            ->sortByDesc('total_importe')
            ->values()
            ->all();
    }

    private function buildTopCpv(string $nutsLike, int $limit, ?int $year = null): array
    {
        $cpvDivisiones = config('contratacion.cpv_divisiones', []);

        [$nLow, $nHigh] = $this->nutsBounds($nutsLike);

        return DB::table('contratos')
            ->where('nuts', '>=', $nLow)->where('nuts', '<', $nHigh)
            ->when($year !== null, fn ($q) => $q->whereNotNull('fecha_publicacion')->whereRaw("{$this->yearExpr} = ?", [(string) $year]))
            ->whereNotNull('cpv')
            ->where('cpv', '!=', '')
            ->selectRaw(SqlDialect::left('cpv', 2).' as cpv2, COUNT(*) as num_contratos, COALESCE(SUM(importe_adjudicacion), 0) as total_importe')
            ->groupByRaw(SqlDialect::left('cpv', 2))
            ->orderByDesc('total_importe')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => [
                'cpv2' => $r->cpv2,
                'descripcion' => $cpvDivisiones[$r->cpv2] ?? "CPV {$r->cpv2}",
                'num_contratos' => (int) $r->num_contratos,
                'total_importe' => (float) $r->total_importe,
            ])
            ->values()
            ->all();
    }

    private function buildTopCpvAnual(string $yearStr, int $limit): array
    {
        $cpvDivisiones = config('contratacion.cpv_divisiones', []);

        return DB::table('contratos')
            ->whereNotNull('fecha_publicacion')
            ->whereRaw("{$this->yearExpr} = ?", [$yearStr])
            ->whereNotNull('cpv')
            ->where('cpv', '!=', '')
            ->selectRaw(SqlDialect::left('cpv', 2).' as cpv2, COUNT(*) as num_contratos, COALESCE(SUM(importe_adjudicacion), 0) as total_importe')
            ->groupByRaw(SqlDialect::left('cpv', 2))
            ->orderByDesc('total_importe')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => [
                'cpv2' => $r->cpv2,
                'descripcion' => $cpvDivisiones[$r->cpv2] ?? "CPV {$r->cpv2}",
                'num_contratos' => (int) $r->num_contratos,
                'total_importe' => (float) $r->total_importe,
            ])
            ->values()
            ->all();
    }

    private function buildAnomaliasResumen(string $nutsLike): array
    {
        [$nLow, $nHigh] = $this->nutsBounds($nutsLike);

        // Organismos con contratos en la zona: semi-join (IN subconsulta) que usa el rango de índice
        // sobre nuts, en vez de un EXISTS doblemente anidado (anomalía→organismo→contratos) que
        // generaba planes inestables de >10s. Un único conteo agrupado por tipo, no cuatro.
        $organismoIds = DB::table('contratos')
            ->where('nuts', '>=', $nLow)->where('nuts', '<', $nHigh)
            ->whereNotNull('organismo_id')
            ->select('organismo_id')
            ->distinct();

        $counts = Anomalia::whereIn('organismo_id', $organismoIds)
            ->groupBy('tipo')
            ->selectRaw('tipo, COUNT(*) as c')
            ->pluck('c', 'tipo');

        return [
            'total' => (int) $counts->sum(),
            'fraccionamiento' => (int) ($counts['fraccionamiento'] ?? 0),
            'concentracion' => (int) ($counts['concentracion'] ?? 0),
            'pico_temporal' => (int) ($counts['pico_temporal'] ?? 0),
        ];
    }

    private function yearExprAnomalia(): string
    {
        return SqlDialect::year('created_at');
    }

    /**
     * Años con datos en la zona (para el selector de la radiografía). Descarta años basura.
     *
     * @return list<int>
     */
    private function aniosDisponibles(string $nutsLike): array
    {
        [$nLow, $nHigh] = $this->nutsBounds($nutsLike);
        $maxYear = (int) date('Y') + 1;

        return DB::table('contratos')
            ->where('nuts', '>=', $nLow)->where('nuts', '<', $nHigh)
            ->whereNotNull('fecha_publicacion')
            ->selectRaw("{$this->yearExpr} as year")
            ->distinct()
            ->orderByDesc('year')
            ->pluck('year')
            ->map(fn ($y) => (int) $y)
            ->filter(fn ($y) => $y >= 2008 && $y <= $maxYear)
            ->values()
            ->all();
    }

    /**
     * Comparación con el año anterior (YoY) de los totales de la zona: contratos, importe y per cápita.
     */
    private function comparativaGeoYear(string $nutsLike, ?int $poblacion, int $year): array
    {
        [$nLow, $nHigh] = $this->nutsBounds($nutsLike);

        $kpiYear = fn (int $y) => DB::table('contratos')
            ->where('nuts', '>=', $nLow)->where('nuts', '<', $nHigh)
            ->whereNotNull('fecha_publicacion')
            ->whereRaw("{$this->yearExpr} = ?", [(string) $y])
            ->selectRaw('COUNT(*) as c, COALESCE(SUM(importe_adjudicacion), 0) as imp')
            ->first();

        $cur = $kpiYear($year);
        $prev = $kpiYear($year - 1);

        $deltaPct = fn (float $a, float $b): ?float => $b > 0 ? round(($a - $b) / $b * 100, 1) : null;
        $perCapita = fn (float $imp): ?float => ($poblacion && $poblacion > 0) ? round($imp / $poblacion, 2) : null;

        return [
            'year' => $year,
            'year_anterior' => $year - 1,
            'contratos' => [
                'actual' => (int) $cur->c,
                'anterior' => (int) $prev->c,
                'delta_pct' => $deltaPct((float) $cur->c, (float) $prev->c),
            ],
            'importe' => [
                'actual' => (float) $cur->imp,
                'anterior' => (float) $prev->imp,
                'delta_pct' => $deltaPct((float) $cur->imp, (float) $prev->imp),
            ],
            'per_capita' => [
                'actual' => $perCapita((float) $cur->imp),
                'anterior' => $perCapita((float) $prev->imp),
            ],
        ];
    }

    /**
     * Convierte un patrón de prefijo NUTS ('ES611%') en límites de rango ['ES611', 'ES612') para
     * que el filtro use el índice btree con parámetros — un LIKE de prefijo parametrizado no lo usa
     * y acaba escaneando los 8M de contratos.
     *
     * @return array{0: string, 1: string}
     */
    private function nutsBounds(string $nutsLike): array
    {
        $low = rtrim($nutsLike, '%');
        $last = substr($low, -1);
        $high = substr($low, 0, -1).chr(ord($last) + 1);

        return [$low, $high];
    }
}
