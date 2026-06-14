<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\SqlDialect;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixDataQuality extends Command
{
    protected $signature = 'data:fix-quality
        {--dry-run : Solo mostrar qué se corregiría, sin aplicar cambios}';

    protected $description = 'Corrige problemas de calidad de datos: fechas absurdas, importes faltantes, NUTS vacíos';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $prefix = $dryRun ? '[DRY-RUN] ' : '';

        $this->info("{$prefix}Corrección de calidad de datos");
        $this->newLine();

        $totalFixed = 0;

        $totalFixed += $this->fixDates00XX($dryRun, $prefix);
        $totalFixed += $this->fixDatesSentinel($dryRun, $prefix);
        $totalFixed += $this->fixDatesOutOfRange($dryRun, $prefix);
        $totalFixed += $this->fixCylImportes($dryRun, $prefix);
        $totalFixed += $this->fixAndaNuts($dryRun, $prefix);
        $totalFixed += $this->fixAndaFechasFromFormalizacion($dryRun, $prefix);

        $this->newLine();
        $this->info("{$prefix}Total registros corregidos: ".number_format($totalFixed));

        return self::SUCCESS;
    }

    /**
     * Fechas con año 00XX → 20XX (ej: 0019-05-23 → 2019-05-23)
     */
    private function fixDates00XX(bool $dryRun, string $prefix): int
    {
        $dateCol = SqlDialect::isPgsql() ? 'fecha_adjudicacion::TEXT' : 'fecha_adjudicacion';

        $count = DB::selectOne("
            SELECT COUNT(*) as c FROM contratos
            WHERE {$dateCol} LIKE '00__-%'
            AND {$dateCol} NOT LIKE '0001-%'
            AND {$dateCol} NOT LIKE '0000-%'
        ")->c;

        $this->line("{$prefix}Fechas 00XX → 20XX: {$count} registros");

        if ($count > 0 && ! $dryRun) {
            $concat = SqlDialect::concat("'20'", 'SUBSTR(fecha_adjudicacion::TEXT, 3)');
            DB::update("
                UPDATE contratos
                SET fecha_adjudicacion = ({$concat})::DATE
                WHERE {$dateCol} LIKE '00__-%'
                AND {$dateCol} NOT LIKE '0001-%'
                AND {$dateCol} NOT LIKE '0000-%'
            ");
            $this->info("  Corregidos: {$count}");
        }

        return $count;
    }

    /**
     * Fechas sentinel 0001-01-XX → NULL
     */
    private function fixDatesSentinel(bool $dryRun, string $prefix): int
    {
        $dateCol = SqlDialect::isPgsql() ? 'fecha_adjudicacion::TEXT' : 'fecha_adjudicacion';

        $count = DB::selectOne("
            SELECT COUNT(*) as c FROM contratos
            WHERE {$dateCol} LIKE '0001-%'
            OR {$dateCol} LIKE '0000-%'
        ")->c;

        $this->line("{$prefix}Fechas sentinel (0001/0000) → NULL: {$count} registros");

        if ($count > 0 && ! $dryRun) {
            DB::update("
                UPDATE contratos
                SET fecha_adjudicacion = NULL
                WHERE {$dateCol} LIKE '0001-%'
                OR {$dateCol} LIKE '0000-%'
            ");
            $this->info("  Corregidos: {$count}");
        }

        return $count;
    }

    /**
     * Fechas fuera de rango → NULL en TODAS las columnas de fecha.
     *
     * Año < 1900 (basura del origen) o futuro imposible distorsionan las gráficas.
     * El límite superior es dinámico (año actual + 1), no un literal, para no tener
     * que tocar el código cada enero. fecha_limite admite hasta 2100 porque los
     * plazos de presentación pueden ser legítimamente futuros.
     */
    private function fixDatesOutOfRange(bool $dryRun, string $prefix): int
    {
        $maxNormal = (int) date('Y') + 1;

        // columna => [añoMín, añoMáx]
        $columns = [
            'fecha_publicacion' => [1900, $maxNormal],
            'fecha_adjudicacion' => [1900, $maxNormal],
            'fecha_formalizacion' => [1900, $maxNormal],
            'fecha_limite' => [1900, 2100],
        ];

        $total = 0;

        foreach ($columns as $col => [$min, $max]) {
            $yearExpr = SqlDialect::yearInt($col);
            $where = "{$col} IS NOT NULL AND ({$yearExpr} < {$min} OR {$yearExpr} > {$max})";

            $count = DB::selectOne("SELECT COUNT(*) as c FROM contratos WHERE {$where}")->c;

            $this->line("{$prefix}{$col} fuera de rango [{$min}-{$max}] → NULL: {$count} registros");

            if ($count > 0 && ! $dryRun) {
                DB::update("UPDATE contratos SET {$col} = NULL WHERE {$where}");
                $this->info("  Corregidos: {$count}");
            }

            $total += $count;
        }

        return $total;
    }

    /**
     * CYL: estimar importe_adjudicacion (sin IVA) desde importe_adjudicacion_con_iva / 1.21
     */
    private function fixCylImportes(bool $dryRun, string $prefix): int
    {
        $count = DB::selectOne("
            SELECT COUNT(*) as c FROM contratos
            WHERE placsp_id LIKE 'CYL-%'
            AND (importe_adjudicacion IS NULL OR importe_adjudicacion = 0)
            AND importe_adjudicacion_con_iva IS NOT NULL
            AND importe_adjudicacion_con_iva > 0
        ")->c;

        $this->line("{$prefix}CYL: estimar importe sin IVA (÷1.21): {$count} registros");

        if ($count > 0 && ! $dryRun) {
            DB::update("
                UPDATE contratos
                SET importe_adjudicacion = ROUND(importe_adjudicacion_con_iva / 1.21, 2)
                WHERE placsp_id LIKE 'CYL-%'
                AND (importe_adjudicacion IS NULL OR importe_adjudicacion = 0)
                AND importe_adjudicacion_con_iva IS NOT NULL
                AND importe_adjudicacion_con_iva > 0
            ");
            $this->info("  Corregidos: {$count}");
        }

        return $count;
    }

    /**
     * ANDA: asignar NUTS por defecto (ES61) cuando está vacío
     */
    private function fixAndaNuts(bool $dryRun, string $prefix): int
    {
        $count = DB::selectOne("
            SELECT COUNT(*) as c FROM contratos
            WHERE placsp_id LIKE 'ANDA-%'
            AND (nuts IS NULL OR nuts = '')
        ")->c;

        $this->line("{$prefix}ANDA: NUTS vacío → ES61 (Andalucía): {$count} registros");

        if ($count > 0 && ! $dryRun) {
            DB::update("
                UPDATE contratos
                SET nuts = 'ES61'
                WHERE placsp_id LIKE 'ANDA-%'
                AND (nuts IS NULL OR nuts = '')
            ");
            $this->info("  Corregidos: {$count}");
        }

        return $count;
    }

    /**
     * ANDA: usar fecha_formalizacion como fecha_adjudicacion cuando está vacía
     */
    private function fixAndaFechasFromFormalizacion(bool $dryRun, string $prefix): int
    {
        $count = DB::selectOne("
            SELECT COUNT(*) as c FROM contratos
            WHERE placsp_id LIKE 'ANDA-%'
            AND fecha_adjudicacion IS NULL
            AND fecha_formalizacion IS NOT NULL
        ")->c;

        $this->line("{$prefix}ANDA: fecha_adjudicacion vacía → usar fecha_formalizacion: {$count} registros");

        if ($count > 0 && ! $dryRun) {
            DB::update("
                UPDATE contratos
                SET fecha_adjudicacion = fecha_formalizacion
                WHERE placsp_id LIKE 'ANDA-%'
                AND fecha_adjudicacion IS NULL
                AND fecha_formalizacion IS NOT NULL
            ");
            $this->info("  Corregidos: {$count}");
        }

        return $count;
    }
}
