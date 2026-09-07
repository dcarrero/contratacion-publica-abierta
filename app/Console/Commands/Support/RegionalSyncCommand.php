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
     * Contratos descartados porque ya estaban en la base por otra fuente
     * (detectados por contenido, no por placsp_id). Ver ExistingContractIndex.
     */
    protected int $duplicates = 0;

    /**
     * Un comando declara aquí que deduplica contra otras fuentes, para que la
     * métrica aparezca en la tabla-resumen aunque salga a cero. Los comandos que
     * no deduplican no ven la fila.
     */
    protected function tracksDuplicates(): bool
    {
        return false;
    }

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

        $notas = $this->importRegionLabel().' años: '.implode(', ', $detalle);
        if ($this->tracksDuplicates()) {
            $notas .= '. Ya presentes por otra fuente: '.number_format($this->duplicates);
        }

        ImportLog::create([
            'fuente_datos_id' => $fuenteDatosId,
            'tipo' => $this->importTipo(),
            'procesados' => $this->processed,
            'nuevos' => $this->created,
            'actualizados' => $this->updated,
            // Los duplicados entre fuentes cuentan como ignorados para que las
            // columnas del log sigan sumando el total de procesados.
            'ignorados' => $this->skipped + $this->duplicates,
            'errores' => $this->errors,
            'duracion_segundos' => $duration,
            'notas' => $notas,
        ]);

        FuenteDatos::where('slug', $this->fuenteSlug())
            ->update(['ultima_sincronizacion' => now()]);
    }

    protected function showSummary(int $duration, bool $dryRun): void
    {
        $prefix = $dryRun ? '[DRY-RUN] ' : '';

        $rows = [
            ['Procesados', number_format($this->processed)],
            ['Nuevos', number_format($this->created)],
            ['Actualizados', number_format($this->updated)],
            ['Sin cambios', number_format($this->skipped)],
        ];

        if ($this->tracksDuplicates()) {
            $rows[] = ['Ya en PLACSP', number_format($this->duplicates)];
        }

        $rows[] = ['Sin NIF adj.', number_format($this->noNif)];
        $rows[] = ['Errores', number_format($this->errors)];
        $rows[] = ['Duracion', "{$duration}s"];

        $this->newLine();
        $this->info("{$prefix}Resumen importacion {$this->importRegionLabel()}:");
        $this->table(['Metrica', 'Valor'], $rows);
    }
}
