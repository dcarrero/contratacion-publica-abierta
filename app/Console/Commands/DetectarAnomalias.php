<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Anomalia;
use App\Support\SqlDialect;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DetectarAnomalias extends Command
{
    protected $signature = 'anomalias:detectar
        {--tipo=all : Tipo de anomalía a detectar (fraccionamiento|concentracion|pico_temporal|all)}
        {--periodo= : Periodo a analizar (e.g. 2026-Q1). Por defecto: trimestre actual}';

    protected $description = 'Detecta anomalías en los contratos públicos';

    private int $created = 0;

    private int $skipped = 0;

    public function handle(): int
    {
        $tipo = $this->option('tipo');
        $periodo = $this->option('periodo') ?: $this->currentPeriodo();

        $this->info("Detectando anomalías — Periodo: {$periodo}");

        if ($tipo === 'all' || $tipo === 'fraccionamiento') {
            $this->detectarFraccionamiento($periodo);
        }

        if ($tipo === 'all' || $tipo === 'concentracion') {
            $this->detectarConcentracion($periodo);
        }

        if ($tipo === 'all' || $tipo === 'pico_temporal') {
            $this->detectarPicoTemporal($periodo);
        }

        $this->newLine();
        $this->table(['Métrica', 'Valor'], [
            ['Anomalías creadas', $this->created],
            ['Duplicadas (omitidas)', $this->skipped],
        ]);

        return self::SUCCESS;
    }

    private function detectarFraccionamiento(string $periodo): void
    {
        $this->info('  Analizando fraccionamiento de contratos menores...');

        $config = config('contratacion.anomalias.fraccionamiento');
        $dias = $config['dias'];
        $minContratos = $config['min_contratos'];
        $umbralServicios = $config['umbral_servicios'];
        $umbralObras = $config['umbral_obras'];
        $ratio = $config['ratio_umbral'];

        $cpv2Expr = SqlDialect::left('cpv', 2);
        $dateFilter = SqlDialect::dateSubFilter('fecha_publicacion', $dias, 'days');

        $results = DB::select("
            SELECT organismo_id, {$cpv2Expr} as cpv2,
                   COUNT(*) as n, SUM(importe_adjudicacion) as total,
                   ".SqlDialect::groupConcat('id').' as contrato_ids
            FROM contratos
            WHERE es_menor = '.SqlDialect::true()."
              AND {$dateFilter}
              AND organismo_id IS NOT NULL
              AND cpv IS NOT NULL
              AND importe_adjudicacion IS NOT NULL
            GROUP BY organismo_id, {$cpv2Expr}
            HAVING COUNT(*) >= ?::INTEGER AND (
                ({$cpv2Expr} IN ('50','51','55','60','63','64','65','66','70','71','72','73','75','76','77','79','80','85','90','92','98')
                 AND SUM(importe_adjudicacion) > ?::NUMERIC * ?::NUMERIC)
                OR
                ({$cpv2Expr} IN ('45')
                 AND SUM(importe_adjudicacion) > ?::NUMERIC * ?::NUMERIC)
                OR
                ({$cpv2Expr} NOT IN ('45','50','51','55','60','63','64','65','66','70','71','72','73','75','76','77','79','80','85','90','92','98')
                 AND SUM(importe_adjudicacion) > ?::NUMERIC * ?::NUMERIC)
            )
        ", [$minContratos, $umbralServicios, $ratio, $umbralObras, $ratio, $umbralServicios, $ratio]);

        foreach ($results as $row) {
            $umbral = $row->cpv2 === '45' ? $umbralObras : $umbralServicios;
            $tipoLabel = $row->cpv2 === '45' ? 'obras' : 'servicios/suministros';
            $total = (float) $row->total;
            $numContratos = (int) $row->n;
            $pctUmbral = round(($total / $umbral) * 100);

            $descripcion = "Posible fraccionamiento: {$numContratos} contratos menores del mismo CPV ({$row->cpv2}) "
                ."por {$this->formatImporte($total)} ({$pctUmbral}% del umbral de {$tipoLabel})";

            $this->upsertAnomalia([
                'tipo' => 'fraccionamiento',
                'severidad' => $pctUmbral >= 100 ? 'alta' : ($pctUmbral >= 90 ? 'media' : 'baja'),
                'descripcion' => $descripcion,
                'organismo_id' => $row->organismo_id,
                'datos_json' => [
                    'cpv2' => $row->cpv2,
                    'num_contratos' => $numContratos,
                    'importe_total' => $total,
                    'umbral' => $umbral,
                    'porcentaje_umbral' => $pctUmbral,
                    'contrato_ids' => array_slice(explode(',', $row->contrato_ids), 0, 20),
                ],
                'periodo' => $periodo,
            ]);
        }

        $this->line('    Encontrados: '.count($results).' posibles fraccionamientos');
    }

    private function detectarConcentracion(string $periodo): void
    {
        $this->info('  Analizando concentración de adjudicatarios...');

        $config = config('contratacion.anomalias.concentracion');
        $umbralPct = $config['umbral_porcentaje'];
        $minContratos = $config['min_contratos'];

        // Organismos con suficientes contratos y su importe total
        $organismos = DB::select('
            SELECT organismo_id, SUM(importe_adjudicacion) as org_total, COUNT(*) as org_count
            FROM contratos
            WHERE organismo_id IS NOT NULL
              AND adjudicatario_id IS NOT NULL
              AND importe_adjudicacion IS NOT NULL
            GROUP BY organismo_id
            HAVING COUNT(*) >= ?
        ', [$minContratos]);

        $count = 0;
        foreach ($organismos as $org) {
            // Top adjudicatario de este organismo
            $top = DB::selectOne('
                SELECT adjudicatario_id, SUM(importe_adjudicacion) as adj_total, COUNT(*) as adj_count
                FROM contratos
                WHERE organismo_id = ?
                  AND adjudicatario_id IS NOT NULL
                  AND importe_adjudicacion IS NOT NULL
                GROUP BY adjudicatario_id
                ORDER BY adj_total DESC
                LIMIT 1
            ', [$org->organismo_id]);

            if (! $top || $org->org_total <= 0) {
                continue;
            }

            $pct = round(($top->adj_total / $org->org_total) * 100, 1);

            if ($pct >= $umbralPct) {
                $descripcion = "Concentración: un adjudicatario acumula el {$pct}% del importe total "
                    ."({$this->formatImporte($top->adj_total)} de {$this->formatImporte($org->org_total)}, "
                    ."{$top->adj_count} contratos)";

                $this->upsertAnomalia([
                    'tipo' => 'concentracion',
                    'severidad' => $pct >= 95 ? 'alta' : ($pct >= 90 ? 'media' : 'baja'),
                    'descripcion' => $descripcion,
                    'organismo_id' => $org->organismo_id,
                    'adjudicatario_id' => $top->adjudicatario_id,
                    'datos_json' => [
                        'porcentaje' => $pct,
                        'importe_adjudicatario' => $top->adj_total,
                        'importe_organismo' => $org->org_total,
                        'num_contratos' => $top->adj_count,
                    ],
                    'periodo' => $periodo,
                ]);

                $count++;
            }
        }

        $this->line("    Encontrados: {$count} concentraciones sospechosas");
    }

    private function detectarPicoTemporal(string $periodo): void
    {
        $this->info('  Analizando picos temporales de gasto...');

        $config = config('contratacion.anomalias.pico_temporal');
        $multiplicador = $config['multiplicador'];
        $minMeses = $config['min_historico_meses'];

        $mesActual = SqlDialect::currentYearMonth();
        $mesExpr = SqlDialect::yearMonth('fecha_publicacion');
        $dateLimit = SqlDialect::dateSubFilter('fecha_publicacion', $minMeses, 'months');
        $datePrevio = 'fecha_publicacion < '.SqlDialect::startOfMonth();

        // Media mensual histórica por organismo (últimos N meses, excluyendo mes actual)
        $medias = DB::select("
            SELECT organismo_id,
                   AVG(mes_total) as media_mensual,
                   COUNT(*) as meses_con_datos
            FROM (
                SELECT organismo_id, {$mesExpr} as mes, SUM(importe_adjudicacion) as mes_total
                FROM contratos
                WHERE {$dateLimit}
                  AND {$datePrevio}
                  AND organismo_id IS NOT NULL
                  AND importe_adjudicacion IS NOT NULL
                GROUP BY organismo_id, mes
            ) sub
            GROUP BY organismo_id
            HAVING COUNT(*) >= 6
        ");

        // Gasto del mes actual por organismo
        $actuales = DB::select("
            SELECT organismo_id, SUM(importe_adjudicacion) as mes_total, COUNT(*) as num_contratos
            FROM contratos
            WHERE {$mesExpr} = {$mesActual}
              AND organismo_id IS NOT NULL
              AND importe_adjudicacion IS NOT NULL
            GROUP BY organismo_id
        ");

        $mediaMap = [];
        foreach ($medias as $m) {
            $mediaMap[$m->organismo_id] = $m;
        }

        $count = 0;
        foreach ($actuales as $actual) {
            if (! isset($mediaMap[$actual->organismo_id])) {
                continue;
            }

            $media = (float) $mediaMap[$actual->organismo_id]->media_mensual;
            if ($media <= 0) {
                continue;
            }

            $ratio = (float) $actual->mes_total / $media;

            if ($ratio >= $multiplicador) {
                $descripcion = "Pico temporal: gasto mensual de {$this->formatImporte($actual->mes_total)} "
                    ."es {$this->formatMultiplicador($ratio)} la media histórica "
                    ."({$this->formatImporte($media)}/mes, {$actual->num_contratos} contratos)";

                $this->upsertAnomalia([
                    'tipo' => 'pico_temporal',
                    'severidad' => $ratio >= 10 ? 'alta' : ($ratio >= 5 ? 'media' : 'baja'),
                    'descripcion' => $descripcion,
                    'organismo_id' => $actual->organismo_id,
                    'datos_json' => [
                        'gasto_mes' => $actual->mes_total,
                        'media_mensual' => round($media, 2),
                        'multiplicador' => round($ratio, 1),
                        'num_contratos' => $actual->num_contratos,
                    ],
                    'periodo' => $periodo,
                ]);

                $count++;
            }
        }

        $this->line("    Encontrados: {$count} picos temporales");
    }

    private function upsertAnomalia(array $data): void
    {
        $existing = Anomalia::where('tipo', $data['tipo'])
            ->where('organismo_id', $data['organismo_id'] ?? null)
            ->where('periodo', $data['periodo'])
            ->when(isset($data['adjudicatario_id']), fn ($q) => $q->where('adjudicatario_id', $data['adjudicatario_id']))
            ->first();

        if ($existing) {
            $existing->update([
                'severidad' => $data['severidad'],
                'descripcion' => $data['descripcion'],
                'datos_json' => $data['datos_json'],
            ]);
            $this->skipped++;
        } else {
            Anomalia::create($data);
            $this->created++;
        }
    }

    private function currentPeriodo(): string
    {
        $quarter = (int) ceil((int) date('n') / 3);

        return date('Y').'-Q'.$quarter;
    }

    private function formatImporte(float|string $valor): string
    {
        $valor = (float) $valor;

        if ($valor >= 1_000_000) {
            return number_format($valor / 1_000_000, 1, ',', '.').' M€';
        }
        if ($valor >= 1_000) {
            return number_format($valor / 1_000, 0, ',', '.').' K€';
        }

        return number_format($valor, 0, ',', '.').' €';
    }

    private function formatMultiplicador(float $ratio): string
    {
        return number_format($ratio, 1, ',', '.').'x';
    }
}
