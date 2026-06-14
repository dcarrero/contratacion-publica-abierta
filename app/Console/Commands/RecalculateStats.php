<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\StatsRecalculator;
use Illuminate\Console\Command;

class RecalculateStats extends Command
{
    protected $signature = 'stats:recalculate
        {--entity=all : Entidad a recalcular: organismos, adjudicatarios, mapa, charts, rankings, informes, grafo, all}';

    protected $description = 'Recalcula contadores desnormalizados (total_contratos, total_importe)';

    public function handle(StatsRecalculator $stats): int
    {
        $entity = $this->option('entity');

        if (! in_array($entity, ['all', 'organismos', 'adjudicatarios', 'mapa', 'charts', 'rankings', 'informes', 'grafo'])) {
            $this->error("Entidad no valida: {$entity}. Usa: organismos, adjudicatarios, mapa, charts, rankings, informes, grafo, all");

            return self::FAILURE;
        }

        $this->info('Recalculando estadisticas...');

        if ($entity === 'all' || $entity === 'organismos') {
            $this->info('  Recalculando organismos...');
            $count = $stats->recalculateOrganismos();
            $this->info("    {$count} organismos actualizados.");
        }

        if ($entity === 'all' || $entity === 'adjudicatarios') {
            $this->info('  Recalculando adjudicatarios...');
            $count = $stats->recalculateAdjudicatarios();
            $this->info("    {$count} adjudicatarios actualizados.");
        }

        if ($entity === 'all' || $entity === 'mapa') {
            $this->info('  Recalculando stats del mapa (CCAA y provincias)...');
            $stats->recalculateMapaStats();
            $this->info('    Mapa stats generadas.');
        }

        if ($entity === 'all' || $entity === 'charts') {
            $this->info('  Recalculando stats de graficas...');
            $error = $stats->recalculateChartStats();
            if ($error !== null) {
                $this->error("    Error al generar JSON: {$error}");
            } else {
                $this->info('    charts.json generado.');
            }
        }

        if ($entity === 'all' || $entity === 'rankings') {
            $this->info('  Recalculando rankings...');
            $error = $stats->recalculateRankingsStats();
            if ($error !== null) {
                $this->error("    Error al generar JSON: {$error}");
            } else {
                $this->info('    rankings.json generado.');
            }
        }

        if ($entity === 'all' || $entity === 'informes') {
            $this->info('  Recalculando stats de informes...');
            $count = $stats->recalculateInformesStats();
            $this->info("    {$count} informes CCAA generados.");
        }

        if ($entity === 'all' || $entity === 'grafo') {
            $this->info('  Recalculando datos del grafo de relaciones...');
            $count = $stats->recalculateGrafoStats();
            $this->info("    {$count} archivos de grafo generados.");
        }

        $this->info('Recalculo completado.');

        return self::SUCCESS;
    }
}
