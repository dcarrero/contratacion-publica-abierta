<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Anomalia;
use App\Models\ComunidadAutonoma;
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
        $nutsLike = "{$nuts2}%";

        // KPIs
        $kpis = DB::table('contratos')
            ->where('nuts', 'LIKE', $nutsLike)
            ->selectRaw('COUNT(*) as total_contratos')
            ->selectRaw('COALESCE(SUM(importe_adjudicacion), 0) as total_importe')
            ->selectRaw(SqlDialect::sumBool('es_menor').' as total_menores')
            ->first();

        $totalContratos = (int) $kpis->total_contratos;
        $totalImporte = (float) $kpis->total_importe;

        $totalOrganismos = (int) DB::table('organismos')
            ->whereExists(function ($q) use ($nutsLike) {
                $q->select(DB::raw(1))->from('contratos')
                    ->whereColumn('contratos.organismo_id', 'organismos.id')
                    ->where('contratos.nuts', 'LIKE', $nutsLike);
            })->count();

        $totalAdjudicatarios = (int) DB::table('adjudicatarios')
            ->whereExists(function ($q) use ($nutsLike) {
                $q->select(DB::raw(1))->from('contratos')
                    ->whereColumn('contratos.adjudicatario_id', 'adjudicatarios.id')
                    ->where('contratos.nuts', 'LIKE', $nutsLike);
            })->count();

        $pctMenores = $totalContratos > 0
            ? round((int) $kpis->total_menores / $totalContratos * 100, 1)
            : 0;
        $importeMedio = $totalContratos > 0
            ? round($totalImporte / $totalContratos, 2)
            : 0;

        // Evolución anual
        $evolucionAnual = DB::table('contratos')
            ->where('nuts', 'LIKE', $nutsLike)
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
        $distribucionTipo = $this->buildDistribucionTipo($nutsLike);

        // Top CPV
        $topCpv = $this->buildTopCpv($nutsLike, config('contratacion.informes.top_cpv', 10));

        // Top adjudicatarios
        $topAdj = config('contratacion.informes.top_adjudicatarios', 20);
        $topAdjudicatarios = DB::table('contratos')
            ->join('adjudicatarios', 'contratos.adjudicatario_id', '=', 'adjudicatarios.id')
            ->where('contratos.nuts', 'LIKE', $nutsLike)
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
            ->where('contratos.nuts', 'LIKE', $nutsLike)
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

        // Anomalías resumen
        $anomaliasResumen = $this->buildAnomaliasResumen($nutsLike);

        return [
            'nuts' => $nuts2,
            'nombre' => $ccaa->nombre,
            'generado_at' => now()->toIso8601String(),
            'kpis' => [
                'total_contratos' => $totalContratos,
                'total_importe' => $totalImporte,
                'total_organismos' => $totalOrganismos,
                'total_adjudicatarios' => $totalAdjudicatarios,
                'pct_menores' => $pctMenores,
                'importe_medio' => $importeMedio,
                'poblacion' => $ccaa->poblacion,
                'gasto_per_capita' => ($ccaa->poblacion && $ccaa->poblacion > 0)
                    ? round($totalImporte / $ccaa->poblacion, 2)
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

    private function buildDistribucionTipo(string $nutsLike): array
    {
        $tipos = config('contratacion.tipos_contrato', []);

        $rawTipos = DB::table('contratos')
            ->where('nuts', 'LIKE', $nutsLike)
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

    private function buildTopCpv(string $nutsLike, int $limit): array
    {
        $cpvDivisiones = config('contratacion.cpv_divisiones', []);

        return DB::table('contratos')
            ->where('nuts', 'LIKE', $nutsLike)
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
        $base = Anomalia::whereHas('organismo', function ($q) use ($nutsLike) {
            $q->whereHas('contratos', function ($q2) use ($nutsLike) {
                $q2->where('nuts', 'LIKE', $nutsLike);
            });
        });

        $total = (int) (clone $base)->count();

        return [
            'total' => $total,
            'fraccionamiento' => (int) (clone $base)->where('tipo', 'fraccionamiento')->count(),
            'concentracion' => (int) (clone $base)->where('tipo', 'concentracion')->count(),
            'pico_temporal' => (int) (clone $base)->where('tipo', 'pico_temporal')->count(),
        ];
    }

    private function yearExprAnomalia(): string
    {
        return SqlDialect::year('created_at');
    }
}
