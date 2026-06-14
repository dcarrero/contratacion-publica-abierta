<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\FuenteDatos;
use App\Models\ImportLog;
use App\Services\ContratoImporter;
use App\Services\Regional\CanariasParser;
use Illuminate\Console\Command;
use League\Csv\Reader;

class SyncCanarias extends Command
{
    protected $signature = 'regional:sync-can
        {file? : Ruta al fichero CSV local (si no se indica, descarga automáticamente)}
        {--chunk=500 : Registros por chunk}
        {--dry-run : Parsear y mostrar sin insertar datos}
        {--limit=0 : Limite de registros a procesar (0 = todos)}';

    protected $description = 'Importa contratos desde CSV de datos abiertos de Canarias';

    private const DIRECT_CSV_URL = 'https://datos.canarias.es/catalogos/general/dataset/c915a5c5-a0da-4e3d-8a97-039f46add0ac/resource/96154b4a-5212-46c7-8bd9-36599a8165b9/download/contratos.csv';

    private int $processed = 0;

    private int $created = 0;

    private int $updated = 0;

    private int $skipped = 0;

    private int $errors = 0;

    private int $noNif = 0;

    public function handle(ContratoImporter $importer): int
    {
        $file = $this->argument('file');
        $dryRun = (bool) $this->option('dry-run');

        $this->info($dryRun ? 'Modo DRY-RUN: no se insertaran datos.' : 'Importando contratos de Canarias...');

        $startTime = microtime(true);
        $fuenteDatosId = $this->resolveFuenteDatos($dryRun);
        if ($fuenteDatosId === false) {
            return self::FAILURE;
        }

        if ($file) {
            if (! file_exists($file)) {
                $this->error("Fichero no encontrado: {$file}");

                return self::FAILURE;
            }
            $this->info("Fichero: {$file}");
        } else {
            $file = $this->downloadCsv();
            if ($file === null) {
                return self::FAILURE;
            }
        }

        $this->importCsvFile($file, $importer, $fuenteDatosId, $dryRun);

        if (! $this->argument('file')) {
            @unlink($file);
        }

        $duration = (int) (microtime(true) - $startTime);
        $this->logImport($fuenteDatosId, $duration, $dryRun);
        $this->showSummary($duration, $dryRun);

        return self::SUCCESS;
    }

    private function downloadCsv(): ?string
    {
        $tempFile = storage_path('app/can_temp.csv');
        $this->line('Descargando CSV de Canarias...');
        $this->line('  URL: '.self::DIRECT_CSV_URL);

        try {
            $ch = curl_init(self::DIRECT_CSV_URL);
            $fp = fopen($tempFile, 'w');
            curl_setopt_array($ch, [
                CURLOPT_FILE => $fp,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 900,
                CURLOPT_FAILONERROR => true,
                CURLOPT_USERAGENT => 'ContratacionAbierta/2.0 (transparencia)',
            ]);
            $success = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            fclose($fp);

            if (! $success || $httpCode !== 200) {
                $this->error("Error descargando: HTTP {$httpCode} {$curlError}");
                @unlink($tempFile);

                return null;
            }

            $fileSize = filesize($tempFile);
            $this->info('  Descargado: '.round($fileSize / 1048576, 1).' MB');

            return $tempFile;
        } catch (\Throwable $e) {
            $this->error("Error descargando: {$e->getMessage()}");
            @unlink($tempFile);

            return null;
        }
    }

    private function importCsvFile(string $file, ContratoImporter $importer, ?int $fuenteDatosId, bool $dryRun): void
    {
        $chunkSize = (int) $this->option('chunk');
        $limit = (int) $this->option('limit');
        $parser = new CanariasParser;

        $reader = Reader::createFromPath($file, 'r');
        $reader->setHeaderOffset(0);

        $firstLine = fgets(fopen($file, 'r'));
        if ($firstLine !== false) {
            if (substr_count($firstLine, ';') > substr_count($firstLine, ',')) {
                $reader->setDelimiter(';');
            }
        }

        $headers = $reader->getHeader();
        $trimmedHeaders = array_map('trim', $headers);
        $needsHeaderFix = $headers !== $trimmedHeaders;
        if ($needsHeaderFix) {
            $this->line('  Headers con espacios detectados, se corregiran automaticamente.');
        }
        $this->info('  Columnas: '.implode(', ', array_slice($trimmedHeaders, 0, 8)).'...');

        $records = $reader->getRecords();
        $totalRecords = $reader->count();

        $bar = $this->output->createProgressBar($totalRecords);
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% | %elapsed:6s% | Creados: %created% | Err: %errors%');
        $bar->setMessage('0', 'created');
        $bar->setMessage('0', 'errors');
        $bar->start();

        $chunk = [];
        foreach ($records as $record) {
            if ($needsHeaderFix) {
                $record = array_combine(array_map('trim', array_keys($record)), array_values($record));
            }
            if ($limit > 0 && $this->processed >= $limit) {
                break;
            }

            $chunk[] = $record;

            if (count($chunk) >= $chunkSize) {
                $this->processChunk($chunk, $parser, $importer, $fuenteDatosId, $dryRun);
                $chunk = [];
                $bar->setMessage((string) $this->created, 'created');
                $bar->setMessage((string) $this->errors, 'errors');
                $bar->advance($chunkSize);
            }
        }

        if (count($chunk) > 0) {
            $this->processChunk($chunk, $parser, $importer, $fuenteDatosId, $dryRun);
            $bar->setMessage((string) $this->created, 'created');
            $bar->setMessage((string) $this->errors, 'errors');
            $bar->advance(count($chunk));
        }

        $bar->finish();
        $this->newLine();
    }

    private function resolveFuenteDatos(bool $dryRun): int|null|false
    {
        if ($dryRun) {
            return null;
        }

        $fuente = FuenteDatos::where('slug', 'can-contratos')->first();
        if (! $fuente) {
            $this->error('Fuente de datos "can-contratos" no encontrada. Ejecuta: php artisan db:seed');

            return false;
        }

        return $fuente->id;
    }

    /**
     * @param  array<int, array<string, string>>  $chunk
     */
    private function processChunk(
        array $chunk,
        CanariasParser $parser,
        ContratoImporter $importer,
        ?int $fuenteDatosId,
        bool $dryRun
    ): void {
        foreach ($chunk as $record) {
            $this->processed++;

            try {
                $data = $parser->parse($record);

                if ($data === null) {
                    $this->skipped++;

                    continue;
                }

                if (empty($data['nif_adjudicatario'])) {
                    $this->noNif++;
                }

                $data['fuente_datos_id'] = $fuenteDatosId;
                $data['tipo_registro'] = 'licitacion';

                if ($dryRun) {
                    $this->created++;

                    continue;
                }

                $result = $importer->import($data);

                match ($result) {
                    'created' => $this->created++,
                    'updated' => $this->updated++,
                    'skipped' => $this->skipped++,
                };

            } catch (\Throwable $e) {
                $this->errors++;

                if ($this->errors <= 10) {
                    $this->warn("Error fila {$this->processed}: {$e->getMessage()}");
                }
            }
        }
    }

    private function logImport(?int $fuenteDatosId, int $duration, bool $dryRun): void
    {
        if ($dryRun || ! $fuenteDatosId) {
            return;
        }

        ImportLog::create([
            'fuente_datos_id' => $fuenteDatosId,
            'tipo' => 'sync-can',
            'procesados' => $this->processed,
            'nuevos' => $this->created,
            'actualizados' => $this->updated,
            'ignorados' => $this->skipped,
            'errores' => $this->errors,
            'duracion_segundos' => $duration,
            'notas' => 'Canarias CSV',
        ]);

        FuenteDatos::where('slug', 'can-contratos')
            ->update(['ultima_sincronizacion' => now()]);
    }

    private function showSummary(int $duration, bool $dryRun): void
    {
        $prefix = $dryRun ? '[DRY-RUN] ' : '';

        $this->newLine();
        $this->info("{$prefix}Resumen importacion Canarias:");
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
