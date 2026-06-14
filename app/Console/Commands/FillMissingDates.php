<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FillMissingDates extends Command
{
    protected $signature = 'data:fill-missing-dates
        {--dry-run : Mostrar cuántos registros se actualizarían sin aplicar cambios}';

    protected $description = 'Rellena fecha_publicacion con fecha_adjudicacion o fecha_formalizacion cuando falta';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $this->info($dryRun ? '[DRY-RUN] Analizando fechas faltantes...' : 'Rellenando fechas faltantes...');

        // Estrategia 1: usar fecha_adjudicacion como fallback
        $countAdj = DB::selectOne('
            SELECT COUNT(*) as c FROM contratos
            WHERE fecha_publicacion IS NULL
            AND fecha_adjudicacion IS NOT NULL
        ')->c;

        $this->line("  Contratos sin fecha_publicacion con fecha_adjudicacion disponible: {$countAdj}");

        if ($countAdj > 0 && ! $dryRun) {
            $updated = DB::update('
                UPDATE contratos
                SET fecha_publicacion = fecha_adjudicacion
                WHERE fecha_publicacion IS NULL
                AND fecha_adjudicacion IS NOT NULL
            ');
            $this->info("  Actualizados con fecha_adjudicacion: {$updated}");
        }

        // Estrategia 2: usar fecha_formalizacion para los que aún quedan
        $countForm = DB::selectOne('
            SELECT COUNT(*) as c FROM contratos
            WHERE fecha_publicacion IS NULL
            AND fecha_formalizacion IS NOT NULL
        ')->c;

        $this->line("  Contratos sin fecha_publicacion con fecha_formalizacion disponible: {$countForm}");

        if ($countForm > 0 && ! $dryRun) {
            $updated = DB::update('
                UPDATE contratos
                SET fecha_publicacion = fecha_formalizacion
                WHERE fecha_publicacion IS NULL
                AND fecha_formalizacion IS NOT NULL
            ');
            $this->info("  Actualizados con fecha_formalizacion: {$updated}");
        }

        // Resumen
        $remaining = DB::selectOne('
            SELECT COUNT(*) as c FROM contratos WHERE fecha_publicacion IS NULL
        ')->c;

        $prefix = $dryRun ? '[DRY-RUN] ' : '';
        $this->newLine();
        $this->table(
            ['Métrica', 'Valor'],
            [
                ["{$prefix}Rellenados con fecha_adjudicacion", number_format($countAdj)],
                ["{$prefix}Rellenados con fecha_formalizacion", number_format($countForm)],
                ['Siguen sin fecha', number_format($remaining)],
            ]
        );

        return self::SUCCESS;
    }
}
