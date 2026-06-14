<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Adjudicatario;
use App\Models\ComunidadAutonoma;
use App\Models\Organismo;
use App\Models\Provincia;
use App\Support\SqlDialect;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class StatsRecalculator
{
    /**
     * Recalculate denormalized counters for organismos.
     * Returns the number of organismos updated.
     */
    public function recalculateOrganismos(): int
    {
        Organismo::withTrashed()->update([
            'total_contratos' => 0,
            'total_importe' => 0,
        ]);

        $organismos = DB::table('contratos')
            ->select('organismo_id')
            ->selectRaw('COUNT(*) as total_contratos')
            ->selectRaw('COALESCE(SUM(importe_adjudicacion), 0) as total_importe')
            ->whereNotNull('organismo_id')
            ->groupBy('organismo_id')
            ->get();

        foreach ($organismos as $row) {
            Organismo::withTrashed()
                ->where('id', $row->organismo_id)
                ->update([
                    'total_contratos' => $row->total_contratos,
                    'total_importe' => $row->total_importe,
                ]);
        }

        return $organismos->count();
    }

    /**
     * Recalculate denormalized counters for adjudicatarios.
     * Returns the number of adjudicatarios updated.
     */
    public function recalculateAdjudicatarios(): int
    {
        Adjudicatario::query()->update([
            'total_contratos' => 0,
            'total_importe' => 0,
        ]);

        $adjudicatarios = DB::table('contratos')
            ->select('adjudicatario_id')
            ->selectRaw('COUNT(*) as total_contratos')
            ->selectRaw('COALESCE(SUM(importe_adjudicacion), 0) as total_importe')
            ->whereNotNull('adjudicatario_id')
            ->groupBy('adjudicatario_id')
            ->get();

        foreach ($adjudicatarios as $row) {
            Adjudicatario::where('id', $row->adjudicatario_id)
                ->update([
                    'total_contratos' => $row->total_contratos,
                    'total_importe' => $row->total_importe,
                ]);
        }

        return $adjudicatarios->count();
    }

    /**
     * Recalculate and write mapa-stats/ccaa.json, provincias-*.json and admin-*.json.
     */
    public function recalculateMapaStats(): void
    {
        // CCAA stats: una sola query agrupada por NUTS2
        $nutsStats = DB::table('contratos')
            ->whereNotNull('nuts')
            ->where('nuts', '!=', '')
            ->selectRaw(SqlDialect::left('nuts', 4).' as nuts_prefix, COUNT(*) as total_contratos, COALESCE(SUM(importe_adjudicacion), 0) as total_importe')
            ->groupByRaw(SqlDialect::left('nuts', 4))
            ->get()
            ->keyBy('nuts_prefix');

        $ccaaStats = ComunidadAutonoma::orderBy('nombre')->get()->map(function (ComunidadAutonoma $ca) use ($nutsStats) {
            // Sumar todos los NUTS que empiezan por este CCAA nuts
            $totalContratos = 0;
            $totalImporte = 0.0;

            foreach ($nutsStats as $prefix => $row) {
                if (str_starts_with((string) $prefix, $ca->nuts)) {
                    $totalContratos += (int) $row->total_contratos;
                    $totalImporte += (float) $row->total_importe;
                }
            }

            $poblacion = $ca->poblacion;
            $gastoPerCapita = ($poblacion && $poblacion > 0)
                ? round($totalImporte / $poblacion, 2)
                : null;

            return [
                'nuts' => $ca->nuts,
                'nombre' => $ca->nombre,
                'total_contratos' => $totalContratos,
                'total_importe' => $totalImporte,
                'poblacion' => $poblacion,
                'gasto_per_capita' => $gastoPerCapita,
            ];
        })->values()->all();

        Storage::put('mapa-stats/ccaa.json', json_encode($ccaaStats, JSON_UNESCAPED_UNICODE));

        // Provincias stats: una sola query agrupada por NUTS3
        $nutsStats3 = DB::table('contratos')
            ->whereNotNull('nuts')
            ->where('nuts', '!=', '')
            ->selectRaw(SqlDialect::left('nuts', 5).' as nuts_prefix, COUNT(*) as total_contratos, COALESCE(SUM(importe_adjudicacion), 0) as total_importe')
            ->groupByRaw(SqlDialect::left('nuts', 5))
            ->get()
            ->keyBy('nuts_prefix');

        $ccaaList = ComunidadAutonoma::all();
        foreach ($ccaaList as $ca) {
            $provStats = Provincia::where('nuts', 'LIKE', "{$ca->nuts}%")
                ->orderBy('nombre')
                ->get()
                ->map(function (Provincia $prov) use ($nutsStats3) {
                    $totalContratos = 0;
                    $totalImporte = 0.0;

                    foreach ($nutsStats3 as $prefix => $row) {
                        if (str_starts_with((string) $prefix, $prov->nuts)) {
                            $totalContratos += (int) $row->total_contratos;
                            $totalImporte += (float) $row->total_importe;
                        }
                    }

                    $poblacion = $prov->poblacion;
                    $gastoPerCapita = ($poblacion && $poblacion > 0)
                        ? round($totalImporte / $poblacion, 2)
                        : null;

                    return [
                        'nuts' => $prov->nuts,
                        'nombre' => $prov->nombre,
                        'total_contratos' => $totalContratos,
                        'total_importe' => $totalImporte,
                        'poblacion' => $poblacion,
                        'gasto_per_capita' => $gastoPerCapita,
                    ];
                })
                ->values()
                ->all();

            Storage::put("mapa-stats/provincias-{$ca->nuts}.json", json_encode($provStats, JSON_UNESCAPED_UNICODE));
        }

        // Administraciones: stats detalladas por CCAA (organismos, adjudicatarios, top organismos)
        foreach ($ccaaList as $ca) {
            $totalOrganismos = Organismo::whereHas('contratos', function ($q) use ($ca) {
                $q->where('nuts', 'LIKE', "{$ca->nuts}%");
            })->count();

            $totalAdjudicatarios = DB::table('adjudicatarios')
                ->whereExists(function ($query) use ($ca) {
                    $query->select(DB::raw(1))
                        ->from('contratos')
                        ->whereColumn('contratos.adjudicatario_id', 'adjudicatarios.id')
                        ->where('contratos.nuts', 'LIKE', "{$ca->nuts}%");
                })
                ->count();

            $topOrganismosIds = Organismo::whereHas('contratos', function ($q) use ($ca) {
                $q->where('nuts', 'LIKE', "{$ca->nuts}%");
            })
                ->where('total_contratos', '>', 0)
                ->orderByDesc('total_importe')
                ->limit(20)
                ->pluck('id')
                ->all();

            // Reutilizar stats de CCAA ya calculadas
            $ccaaRow = collect($ccaaStats)->firstWhere('nuts', $ca->nuts);

            $adminData = [
                'total_contratos' => $ccaaRow['total_contratos'] ?? 0,
                'total_importe' => $ccaaRow['total_importe'] ?? 0.0,
                'total_organismos' => $totalOrganismos,
                'total_adjudicatarios' => $totalAdjudicatarios,
                'top_organismos_ids' => $topOrganismosIds,
            ];

            Storage::put("mapa-stats/admin-{$ca->nuts}.json", json_encode($adminData, JSON_UNESCAPED_UNICODE));
        }
    }

    /**
     * Recalculate and write mapa-stats/charts.json.
     * Returns null on success or an error string on JSON encoding failure.
     */
    public function recalculateChartStats(): ?string
    {
        $yearExpr = SqlDialect::year('fecha_publicacion');
        $monthExpr = SqlDialect::yearMonth('fecha_publicacion');
        $cpv2Expr = SqlDialect::left('cpv', 2);

        // 1. Evolución anual
        $evolucionAnual = DB::table('contratos')
            ->whereNotNull('fecha_publicacion')
            ->selectRaw("{$yearExpr} as year, COUNT(*) as num_contratos, COALESCE(SUM(importe_adjudicacion), 0) as total_importe")
            ->groupByRaw($yearExpr)
            ->orderBy('year')
            ->get()
            ->map(fn ($r) => ['year' => (int) $r->year, 'num_contratos' => (int) $r->num_contratos, 'total_importe' => (float) $r->total_importe])
            ->values();

        // 2. Distribución por tipo — normalizar texto libre a códigos CODICE
        $tipos = config('contratacion.tipos_contrato', []);
        $tipoNorm = [
            'servicios' => 2, 'serveis' => 2, 'servicio' => 2,
            'suministros' => 1, 'subministraments' => 1, 'suministro' => 1,
            'obras' => 3, 'obres' => 3,
            'patrimonial' => 7, 'patrimoniales' => 7,
        ];

        $rawTipos = DB::table('contratos')
            ->selectRaw('tipo_contrato, COUNT(*) as num_contratos, COALESCE(SUM(importe_adjudicacion), 0) as total_importe')
            ->groupBy('tipo_contrato')
            ->get();

        $agrupado = [];
        foreach ($rawTipos as $r) {
            $val = trim((string) ($r->tipo_contrato ?? ''));
            // Si es numérico y está en config → usar como código
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
            } elseif (str_contains(mb_strtolower($val), 'privad') || str_contains(mb_strtolower($val), 'privat')) {
                $key = 999;
            } else {
                $key = 999;
            }

            if (! isset($agrupado[$key])) {
                $agrupado[$key] = ['num_contratos' => 0, 'total_importe' => 0.0];
            }
            $agrupado[$key]['num_contratos'] += (int) $r->num_contratos;
            $agrupado[$key]['total_importe'] += (float) $r->total_importe;
        }

        $distribucionTipo = collect($agrupado)
            ->map(fn ($v, $k) => [
                'tipo_contrato' => $k,
                'label' => $tipos[$k] ?? 'Otros',
                'num_contratos' => $v['num_contratos'],
                'total_importe' => $v['total_importe'],
            ])
            ->sortByDesc('total_importe')
            ->values();

        // 3. Top CPV (2 dígitos)
        $cpvDivisiones = config('contratacion.cpv_divisiones', []);
        $topCpv = DB::table('contratos')
            ->whereNotNull('cpv')
            ->where('cpv', '!=', '')
            ->selectRaw("{$cpv2Expr} as cpv2, COUNT(*) as num_contratos, COALESCE(SUM(importe_adjudicacion), 0) as total_importe")
            ->groupByRaw($cpv2Expr)
            ->orderByDesc('total_importe')
            ->limit(10)
            ->get()
            ->map(fn ($r) => [
                'cpv2' => $r->cpv2,
                'descripcion' => $cpvDivisiones[$r->cpv2] ?? "CPV {$r->cpv2}",
                'num_contratos' => (int) $r->num_contratos,
                'total_importe' => (float) $r->total_importe,
            ])
            ->values();

        // 4. Umbral contratos menores — histograma con rangos finos cerca de 15K€ y 40K€
        $rangos = [
            [0, 1000, '0-1K'],
            [1000, 3000, '1-3K'],
            [3000, 5000, '3-5K'],
            [5000, 7000, '5-7K'],
            [7000, 9000, '7-9K'],
            [9000, 10000, '9-10K'],
            [10000, 11000, '10-11K'],
            [11000, 12000, '11-12K'],
            [12000, 13000, '12-13K'],
            [13000, 13500, '13-13,5K'],
            [13500, 14000, '13,5-14K'],
            [14000, 14500, '14-14,5K'],
            [14500, 14990, '14,5-15K'],
            [15000, 20000, '15-20K'],
            [20000, 25000, '20-25K'],
            [25000, 30000, '25-30K'],
            [30000, 35000, '30-35K'],
            [35000, 37000, '35-37K'],
            [37000, 38000, '37-38K'],
            [38000, 39000, '38-39K'],
            [39000, 39500, '39-39,5K'],
            [39500, 39990, '39,5-40K'],
            [40000, 50000, '40-50K'],
            [50000, null, '50K+'],
        ];

        $umbralMenores = [];
        foreach ($rangos as [$min, $max, $label]) {
            $query = DB::table('contratos')
                ->where('es_menor', true)
                ->whereNotNull('importe_adjudicacion')
                ->where('importe_adjudicacion', '>=', $min);

            if ($max !== null) {
                $query->where('importe_adjudicacion', '<', $max);
            }

            $umbralMenores[] = [
                'rango' => $label,
                'min' => $min,
                'max' => $max,
                'num_contratos' => (int) $query->count(),
            ];
        }

        // 5. Evolución mensual (últimos 24 meses)
        $desde = now()->subMonths(24)->format('Y-m-d');
        $evolucionMensual = DB::table('contratos')
            ->whereNotNull('fecha_publicacion')
            ->where('fecha_publicacion', '>=', $desde)
            ->selectRaw("{$monthExpr} as mes, COUNT(*) as num_contratos, COALESCE(SUM(importe_adjudicacion), 0) as total_importe")
            ->groupByRaw($monthExpr)
            ->orderBy('mes')
            ->get()
            ->map(fn ($r) => ['mes' => $r->mes, 'num_contratos' => (int) $r->num_contratos, 'total_importe' => (float) $r->total_importe])
            ->values();

        // 6. Concentración: top 10 adjudicatarios vs total
        $totalImporte = (float) Adjudicatario::sum('total_importe');
        $top10 = Adjudicatario::orderByDesc('total_importe')
            ->limit(10)
            ->get(['nombre', 'nif', 'total_contratos', 'total_importe']);
        $top10Importe = (float) $top10->sum('total_importe');
        $concentracion = [
            'top10_importe' => $top10Importe,
            'total_importe' => $totalImporte,
            'porcentaje' => $totalImporte > 0 ? round($top10Importe / $totalImporte * 100, 2) : 0,
            'top10' => $top10
                ->map(fn ($a) => [
                    'nombre' => $a->nombre,
                    'nif' => $a->nif,
                    'total_contratos' => (int) $a->total_contratos,
                    'total_importe' => (float) $a->total_importe,
                ])
                ->values()
                ->all(),
        ];

        // 7. Distribución por comunidades autónomas (de stats de mapa ya pre-computados)
        $distribucionCcaa = [];
        if (Storage::exists('mapa-stats/ccaa.json')) {
            $ccaaStats = json_decode(Storage::get('mapa-stats/ccaa.json'), true) ?? [];
            $distribucionCcaa = collect($ccaaStats)
                ->filter(fn ($c) => $c['total_contratos'] > 0)
                ->sortByDesc('total_importe')
                ->values()
                ->all();
        }

        $charts = [
            'evolucion_anual' => $evolucionAnual,
            'distribucion_tipo' => $distribucionTipo,
            'distribucion_ccaa' => $distribucionCcaa,
            'top_cpv' => $topCpv,
            'umbral_menores' => $umbralMenores,
            'evolucion_mensual' => $evolucionMensual,
            'concentracion' => $concentracion,
            'generado_at' => now()->toIso8601String(),
        ];

        $json = json_encode($charts, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            return json_last_error_msg();
        }

        Storage::put('mapa-stats/charts.json', $json);

        return null;
    }

    /**
     * Recalculate and write mapa-stats/rankings.json.
     * Returns null on success or an error string on JSON encoding failure.
     */
    public function recalculateRankingsStats(): ?string
    {
        $yearExpr = SqlDialect::year('fecha_publicacion');

        // A) Top 50 organismos por % contratos menores (mín. 20 contratos)
        $topMenores = DB::table('contratos')
            ->join('organismos', 'contratos.organismo_id', '=', 'organismos.id')
            ->select(
                'organismos.id',
                'organismos.nombre',
                'organismos.nif',
            )
            ->selectRaw('COUNT(*) as total_contratos')
            ->selectRaw(SqlDialect::sumBool('es_menor').' as total_menores')
            ->selectRaw('COALESCE(SUM(importe_adjudicacion), 0) as total_importe')
            ->groupBy('organismos.id', 'organismos.nombre', 'organismos.nif')
            ->havingRaw('COUNT(*) >= 20')
            ->get()
            ->map(function ($r) {
                $r->pct_menores = $r->total_contratos > 0
                    ? round((int) $r->total_menores / (int) $r->total_contratos * 100, 1)
                    : 0;

                return $r;
            })
            ->sortByDesc('pct_menores')
            ->take(50)
            ->map(fn ($r) => [
                'nombre' => $r->nombre,
                'nif' => $r->nif,
                'total_contratos' => (int) $r->total_contratos,
                'total_menores' => (int) $r->total_menores,
                'pct_menores' => $r->pct_menores,
                'total_importe' => (float) $r->total_importe,
            ])
            ->values()
            ->all();

        // B) Top 30 organismos por importe medio (mín. 10 contratos)
        $topImporteMedio = DB::table('contratos')
            ->join('organismos', 'contratos.organismo_id', '=', 'organismos.id')
            ->select('organismos.nombre', 'organismos.nif')
            ->selectRaw('COUNT(*) as total_contratos')
            ->selectRaw('COALESCE(SUM(importe_adjudicacion), 0) as total_importe')
            ->selectRaw('COALESCE(AVG(importe_adjudicacion), 0) as importe_medio')
            ->whereNotNull('importe_adjudicacion')
            ->where('importe_adjudicacion', '>', 0)
            ->groupBy('organismos.id', 'organismos.nombre', 'organismos.nif')
            ->havingRaw('COUNT(*) >= 10')
            ->orderByDesc('importe_medio')
            ->limit(30)
            ->get()
            ->map(fn ($r) => [
                'nombre' => $r->nombre,
                'nif' => $r->nif,
                'total_contratos' => (int) $r->total_contratos,
                'total_importe' => (float) $r->total_importe,
                'importe_medio' => round((float) $r->importe_medio, 2),
            ])
            ->values()
            ->all();

        // C) Top 30 organismos con más anomalías (no revisadas)
        $topAnomalias = DB::table('anomalias')
            ->join('organismos', 'anomalias.organismo_id', '=', 'organismos.id')
            ->where('anomalias.revisada', false)
            ->select('organismos.nombre', 'organismos.nif')
            ->selectRaw('COUNT(*) as total_anomalias')
            ->selectRaw("SUM(CASE WHEN anomalias.severidad = 'alta' THEN 1 ELSE 0 END) as anomalias_alta")
            ->selectRaw("SUM(CASE WHEN anomalias.severidad = 'media' THEN 1 ELSE 0 END) as anomalias_media")
            ->groupBy('organismos.id', 'organismos.nombre', 'organismos.nif')
            ->orderByDesc('total_anomalias')
            ->limit(30)
            ->get()
            ->map(fn ($r) => [
                'nombre' => $r->nombre,
                'nif' => $r->nif,
                'total_anomalias' => (int) $r->total_anomalias,
                'anomalias_alta' => (int) $r->anomalias_alta,
                'anomalias_media' => (int) $r->anomalias_media,
            ])
            ->values()
            ->all();

        // D) Comparativa CCAA — indicadores avanzados
        $ccaaList = ComunidadAutonoma::orderBy('nombre')->get();

        // Stats base por NUTS2
        $nutsStats = DB::table('contratos')
            ->whereNotNull('nuts')
            ->where('nuts', '!=', '')
            ->selectRaw(SqlDialect::left('nuts', 4).' as nuts2')
            ->selectRaw('COUNT(*) as total_contratos')
            ->selectRaw('COALESCE(SUM(importe_adjudicacion), 0) as total_importe')
            ->selectRaw(SqlDialect::sumBool('es_menor').' as total_menores')
            ->selectRaw('COALESCE(AVG(CASE WHEN num_ofertas > 0 THEN num_ofertas END), 0) as media_ofertas')
            ->groupByRaw(SqlDialect::left('nuts', 4))
            ->get()
            ->keyBy('nuts2');

        // YoY: último año completo vs anterior
        $lastYear = (int) now()->subYear()->format('Y');
        $prevYear = $lastYear - 1;

        $yearStats = DB::table('contratos')
            ->whereNotNull('nuts')
            ->where('nuts', '!=', '')
            ->whereNotNull('fecha_publicacion')
            ->whereRaw(SqlDialect::yearInt('fecha_publicacion')." IN ({$lastYear}, {$prevYear})")
            ->selectRaw(SqlDialect::left('nuts', 4).' as nuts2')
            ->selectRaw(SqlDialect::yearInt('fecha_publicacion').' as year')
            ->selectRaw('COALESCE(SUM(importe_adjudicacion), 0) as total_importe')
            ->groupByRaw(SqlDialect::left('nuts', 4).', '.SqlDialect::yearInt('fecha_publicacion'))
            ->get();

        $yoyByNuts = [];
        foreach ($yearStats as $row) {
            $yoyByNuts[$row->nuts2][(int) $row->year] = (float) $row->total_importe;
        }

        $comparativaCcaa = $ccaaList->map(function (ComunidadAutonoma $ca) use ($nutsStats, $yoyByNuts, $lastYear, $prevYear) {
            // Sumar NUTS que empiezan por el código de la CCAA
            $totalContratos = 0;
            $totalImporte = 0.0;
            $totalMenores = 0;
            $sumaOfertas = 0.0;
            $countOfertas = 0;

            foreach ($nutsStats as $nuts2 => $row) {
                if (str_starts_with((string) $nuts2, $ca->nuts)) {
                    $totalContratos += (int) $row->total_contratos;
                    $totalImporte += (float) $row->total_importe;
                    $totalMenores += (int) $row->total_menores;
                    if ((float) $row->media_ofertas > 0) {
                        $sumaOfertas += (float) $row->media_ofertas * (int) $row->total_contratos;
                        $countOfertas += (int) $row->total_contratos;
                    }
                }
            }

            $importeLastYear = 0.0;
            $importePrevYear = 0.0;
            foreach ($yoyByNuts as $nuts2 => $years) {
                if (str_starts_with((string) $nuts2, $ca->nuts)) {
                    $importeLastYear += $years[$lastYear] ?? 0.0;
                    $importePrevYear += $years[$prevYear] ?? 0.0;
                }
            }

            $crecimientoYoy = $importePrevYear > 0
                ? round(($importeLastYear - $importePrevYear) / $importePrevYear * 100, 1)
                : null;

            $poblacion = $ca->poblacion;
            $gastoPerCapita = ($poblacion && $poblacion > 0)
                ? round($totalImporte / $poblacion, 2)
                : null;

            return [
                'nombre' => $ca->nombre,
                'nuts' => $ca->nuts,
                'total_contratos' => $totalContratos,
                'total_importe' => $totalImporte,
                'importe_medio' => $totalContratos > 0 ? round($totalImporte / $totalContratos, 2) : 0,
                'pct_menores' => $totalContratos > 0 ? round($totalMenores / $totalContratos * 100, 1) : 0,
                'media_ofertas' => $countOfertas > 0 ? round($sumaOfertas / $countOfertas, 1) : null,
                'crecimiento_yoy' => $crecimientoYoy,
                'importe_last_year' => $importeLastYear,
                'importe_prev_year' => $importePrevYear,
                'poblacion' => $poblacion,
                'gasto_per_capita' => $gastoPerCapita,
            ];
        })
            ->filter(fn ($c) => $c['total_contratos'] > 0)
            ->sortByDesc('total_importe')
            ->values()
            ->all();

        $rankings = [
            'top_organismos_pct_menores' => $topMenores,
            'top_organismos_importe_medio' => $topImporteMedio,
            'top_organismos_anomalias' => $topAnomalias,
            'comparativa_ccaa' => $comparativaCcaa,
            'ultimo_ano' => $lastYear,
            'generado_at' => now()->toIso8601String(),
        ];

        $json = json_encode($rankings, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            return json_last_error_msg();
        }

        Storage::put('mapa-stats/rankings.json', $json);

        return null;
    }

    /**
     * Recalculate and write mapa-stats/informe-*.json and informe-nacional-*.json.
     * Returns the number of informe files generated.
     */
    public function recalculateInformesStats(): int
    {
        $builder = new InformeDataBuilder;
        $ccaaList = ComunidadAutonoma::orderBy('nombre')->get();

        foreach ($ccaaList as $ca) {
            $data = $builder->buildCcaa($ca->nuts);
            $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
            Storage::put("mapa-stats/informe-{$ca->nuts}.json", $json);
        }

        // Informe nacional del último año completo
        $lastYear = (int) now()->subYear()->format('Y');
        $data = $builder->buildAnual($lastYear);
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
        Storage::put("mapa-stats/informe-nacional-{$lastYear}.json", $json);

        return $ccaaList->count();
    }

    /**
     * Recalculate and write mapa-stats/grafo-*.json and grafo-nacional.json.
     * Returns the number of grafo files generated (CCAA count + 1 for nacional).
     */
    public function recalculateGrafoStats(): int
    {
        $ccaaList = ComunidadAutonoma::orderBy('nombre')->get();
        $topRelaciones = config('contratacion.grafo.top_relaciones', 150);
        $topNodos = config('contratacion.grafo.top_nodos', 50);

        foreach ($ccaaList as $ca) {
            $data = $this->buildGrafoData("{$ca->nuts}%", $topRelaciones, $topNodos);
            $data['nuts'] = $ca->nuts;
            $data['nombre'] = $ca->nombre;
            $data['generado_at'] = now()->toIso8601String();

            $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            Storage::put("mapa-stats/grafo-{$ca->nuts}.json", $json);
        }

        // Grafo nacional: top relaciones de toda España
        $data = $this->buildGrafoData('%', $topRelaciones * 2, $topNodos * 2);
        $data['nombre'] = 'España';
        $data['generado_at'] = now()->toIso8601String();

        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        Storage::put('mapa-stats/grafo-nacional.json', $json);

        return $ccaaList->count() + 1;
    }

    private function buildGrafoData(string $nutsLike, int $topRelaciones, int $topNodos): array
    {
        // Top relaciones organismo-adjudicatario por importe
        $relaciones = DB::table('contratos')
            ->join('organismos', 'contratos.organismo_id', '=', 'organismos.id')
            ->join('adjudicatarios', 'contratos.adjudicatario_id', '=', 'adjudicatarios.id')
            ->where('contratos.nuts', 'LIKE', $nutsLike)
            ->whereNotNull('contratos.adjudicatario_id')
            ->select(
                'organismos.id as org_id',
                'organismos.nombre as org_nombre',
                'organismos.nif as org_nif',
                'adjudicatarios.id as adj_id',
                'adjudicatarios.nombre as adj_nombre',
                'adjudicatarios.nif as adj_nif',
            )
            ->selectRaw('COUNT(*) as num_contratos')
            ->selectRaw('COALESCE(SUM(contratos.importe_adjudicacion), 0) as total_importe')
            ->groupBy('organismos.id', 'organismos.nombre', 'organismos.nif', 'adjudicatarios.id', 'adjudicatarios.nombre', 'adjudicatarios.nif')
            ->havingRaw('COUNT(*) >= 3')
            ->orderByDesc('total_importe')
            ->limit($topRelaciones)
            ->get();

        // Construir nodos únicos y enlaces
        $nodos = [];
        $enlaces = [];

        foreach ($relaciones as $r) {
            $orgKey = 'org_'.$r->org_id;
            $adjKey = 'adj_'.$r->adj_id;

            if (! isset($nodos[$orgKey])) {
                $nodos[$orgKey] = [
                    'id' => $orgKey,
                    'label' => $r->org_nombre,
                    'nif' => $r->org_nif,
                    'tipo' => 'organismo',
                    'total_importe' => 0,
                ];
            }
            $nodos[$orgKey]['total_importe'] += (float) $r->total_importe;

            if (! isset($nodos[$adjKey])) {
                $nodos[$adjKey] = [
                    'id' => $adjKey,
                    'label' => $r->adj_nombre,
                    'nif' => $r->adj_nif,
                    'tipo' => 'adjudicatario',
                    'total_importe' => 0,
                ];
            }
            $nodos[$adjKey]['total_importe'] += (float) $r->total_importe;

            $enlaces[] = [
                'source' => $orgKey,
                'target' => $adjKey,
                'num_contratos' => (int) $r->num_contratos,
                'total_importe' => (float) $r->total_importe,
            ];
        }

        // Limitar nodos si hay demasiados
        if (count($nodos) > $topNodos * 2) {
            $topNodosSorted = collect($nodos)->sortByDesc('total_importe')->take($topNodos * 2)->keys()->all();
            $nodos = array_intersect_key($nodos, array_flip($topNodosSorted));
            $enlaces = array_filter($enlaces, fn ($e) => isset($nodos[$e['source']]) && isset($nodos[$e['target']]));
            $enlaces = array_values($enlaces);
        }

        return [
            'nodes' => array_values($nodos),
            'links' => $enlaces,
        ];
    }
}
