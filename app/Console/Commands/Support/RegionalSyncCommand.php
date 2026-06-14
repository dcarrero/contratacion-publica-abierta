<?php

declare(strict_types=1);

namespace App\Console\Commands\Support;

use App\Models\FuenteDatos;
use App\Models\ImportLog;
use Illuminate\Console\Command;

abstract class RegionalSyncCommand extends Command
{
    protected int $processed = 0;

    protected int $created = 0;

    protected int $updated = 0;

    protected int $skipped = 0;

    protected int $errors = 0;

    protected int $noNif = 0;

    /**
     * Tipo de registro para ImportLog (e.g. 'sync-ast').
     */
    abstract protected function importTipo(): string;

    /**
     * Etiqueta de región para las notas del ImportLog (e.g. 'Asturias').
     */
    abstract protected function importRegionLabel(): string;

    /**
     * Slug de la fuente de datos en la tabla fuentes_datos.
     */
    abstract protected function fuenteSlug(): string;

    protected function logImport(?int $fuenteDatosId, int $duration, bool $dryRun, array $detalle = []): void
    {
        if ($dryRun || ! $fuenteDatosId) {
            return;
        }

        ImportLog::create([
            'fuente_datos_id' => $fuenteDatosId,
            'tipo' => $this->importTipo(),
            'procesados' => $this->processed,
            'nuevos' => $this->created,
            'actualizados' => $this->updated,
            'ignorados' => $this->skipped,
            'errores' => $this->errors,
            'duracion_segundos' => $duration,
            'notas' => $this->importRegionLabel().' años: '.implode(', ', $detalle),
        ]);

        FuenteDatos::where('slug', $this->fuenteSlug())
            ->update(['ultima_sincronizacion' => now()]);
    }

    protected function showSummary(int $duration, bool $dryRun): void
    {
        $prefix = $dryRun ? '[DRY-RUN] ' : '';

        $this->newLine();
        $this->info("{$prefix}Resumen importacion {$this->importRegionLabel()}:");
        $this->table(
            ['Metrica', 'Valor'],
            [
                ['Procesados', number_format($this->processed)],
                ['Nuevos', number_format($this->created)],
                ['Actualizados', number_format($this->updated)],
                ['Sin cambios', number_format($this->skipped)],
                ['Sin NIF adj.', number_format($this->noNif)],
                ['Errores', number_format($this->errors)],
                ['Duracion', "{$duration}s"],
            ]
        );
    }
}
