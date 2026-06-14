<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\NormalizeName;
use App\Models\FuenteDatos;
use App\Models\ImportLog;
use App\Models\Organismo;
use Illuminate\Console\Command;
use League\Csv\Reader;

class SyncPlacspOrganos extends Command
{
    protected $signature = 'placsp:sync-organos
        {file : Ruta al CSV con organos de contratacion}
        {--dry-run : Mostrar cambios sin aplicarlos}
        {--delimiter=; : Delimitador del CSV}';

    protected $description = 'Importa/actualiza organismos desde un CSV de órganos PLACSP';

    private int $processed = 0;

    private int $created = 0;

    private int $updated = 0;

    private int $skipped = 0;

    private int $errors = 0;

    public function handle(NormalizeName $normalizeName): int
    {
        $filePath = $this->argument('file');
        $dryRun = (bool) $this->option('dry-run');
        $delimiter = $this->option('delimiter');

        if (! file_exists($filePath)) {
            $this->error("Archivo no encontrado: {$filePath}");

            return self::FAILURE;
        }

        $this->info($dryRun ? 'Modo DRY-RUN: no se insertaran datos.' : 'Importando organos de contratacion...');

        $startTime = microtime(true);

        // Resolver fuente de datos
        $fuenteDatosId = null;
        if (! $dryRun) {
            $fuente = FuenteDatos::where('slug', 'placsp-organos')->first();
            if ($fuente) {
                $fuenteDatosId = $fuente->id;
            }
        }

        $csv = Reader::createFromPath($filePath, 'r');
        $csv->setDelimiter($delimiter);
        $csv->setHeaderOffset(0);

        $records = $csv->getRecords();

        foreach ($records as $record) {
            $this->processRecord($record, $normalizeName, $dryRun);

            if ($this->processed > 0 && $this->processed % 100 === 0) {
                $this->line("  ... {$this->processed} registros procesados ({$this->created} nuevos, {$this->updated} actualizados)");
            }
        }

        $duration = (int) (microtime(true) - $startTime);

        // Registrar ImportLog
        if (! $dryRun && $fuenteDatosId) {
            ImportLog::create([
                'fuente_datos_id' => $fuenteDatosId,
                'tipo' => 'sync-organos',
                'procesados' => $this->processed,
                'nuevos' => $this->created,
                'actualizados' => $this->updated,
                'ignorados' => $this->skipped,
                'errores' => $this->errors,
                'duracion_segundos' => $duration,
                'notas' => "CSV: {$filePath}",
            ]);

            FuenteDatos::where('slug', 'placsp-organos')
                ->update(['ultima_sincronizacion' => now()]);
        }

        $this->showSummary($duration, $dryRun);

        return self::SUCCESS;
    }

    private function processRecord(array $record, NormalizeName $normalizeName, bool $dryRun): void
    {
        $this->processed++;

        try {
            $nif = $this->findColumn($record, ['nif', 'NIF', 'nif_organo', 'CIF']);

            if ($nif === null || trim($nif) === '') {
                $this->skipped++;

                return;
            }

            $nif = mb_strtoupper(trim($nif));

            $nombre = $this->findColumn($record, ['nombre', 'Nombre', 'nombre_organo', 'NOMBRE', 'Órgano de Contratación'])
                ?? $nif;
            $dir3 = $this->findColumn($record, ['dir3', 'DIR3', 'codigo_dir3']);
            $tipo = $this->findColumn($record, ['tipo', 'Tipo', 'tipo_organo', 'TIPO']);
            $urlPerfil = $this->findColumn($record, ['url_perfil', 'url_perfil_placsp', 'URL', 'url']);

            if ($dryRun) {
                $exists = Organismo::where('nif', $nif)->exists();
                $action = $exists ? 'actualizar' : 'crear';
                $this->line("  [{$action}] {$nif} — {$nombre}");

                if ($exists) {
                    $this->updated++;
                } else {
                    $this->created++;
                }

                return;
            }

            $organismo = Organismo::where('nif', $nif)->first();

            if ($organismo) {
                $updates = [];
                if (empty($organismo->dir3) && ! empty($dir3)) {
                    $updates['dir3'] = $dir3;
                }
                if (empty($organismo->tipo) && ! empty($tipo)) {
                    $updates['tipo'] = $tipo;
                }
                if (empty($organismo->url_perfil_placsp) && ! empty($urlPerfil)) {
                    $updates['url_perfil_placsp'] = $urlPerfil;
                }

                if (! empty($updates)) {
                    $organismo->update($updates);
                    $this->updated++;
                } else {
                    $this->skipped++;
                }
            } else {
                Organismo::create([
                    'nif' => $nif,
                    'nombre' => $nombre,
                    'nombre_normalizado' => $normalizeName($nombre),
                    'dir3' => $dir3,
                    'tipo' => $tipo,
                    'url_perfil_placsp' => $urlPerfil,
                    'activo' => true,
                ]);
                $this->created++;
            }
        } catch (\Throwable $e) {
            $this->errors++;
            $this->warn("Error en registro: {$e->getMessage()}");
        }
    }

    /**
     * Busca un valor en el registro usando múltiples posibles nombres de columna.
     */
    private function findColumn(array $record, array $possibleNames): ?string
    {
        foreach ($possibleNames as $name) {
            if (isset($record[$name]) && trim($record[$name]) !== '') {
                return trim($record[$name]);
            }
        }

        return null;
    }

    private function showSummary(int $duration, bool $dryRun): void
    {
        $prefix = $dryRun ? '[DRY-RUN] ' : '';

        $this->newLine();
        $this->info("{$prefix}Resumen importacion organos:");
        $this->table(
            ['Metrica', 'Valor'],
            [
                ['Total procesados', number_format($this->processed)],
                ['Nuevos', number_format($this->created)],
                ['Actualizados', number_format($this->updated)],
                ['Sin cambios / filtrados', number_format($this->skipped)],
                ['Errores', number_format($this->errors)],
                ['Duracion', "{$duration}s"],
            ]
        );
    }
}
