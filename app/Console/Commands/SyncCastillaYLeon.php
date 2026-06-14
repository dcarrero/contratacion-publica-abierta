<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\FuenteDatos;
use App\Models\ImportLog;
use App\Services\ContratoImporter;
use App\Services\Regional\CastillaYLeonParser;
use Illuminate\Console\Command;
use League\Csv\Reader;

class SyncCastillaYLeon extends Command
{
    protected $signature = 'regional:sync-cyl
        {file? : Ruta al fichero CSV local (si no se indica, descarga automáticamente)}
        {--dataset=* : Datasets a descargar: menores, sacyl, ordinarios (por defecto: todos)}
        {--chunk=500 : Registros por chunk}
        {--dry-run : Parsear y mostrar sin insertar datos}
        {--limit=0 : Limite de registros a procesar (0 = todos)}';

    protected $description = 'Importa contratos desde CSV de la Junta de Castilla y Leon (descarga automática o fichero local)';

    private const DATASETS = [
        'menores' => 'contratos-menores',
        'sacyl' => 'contratos-menores-sacyl',
        'ordinarios' => 'contratos-ordinarios',
    ];

    private const API_BASE = 'https://analisis.datosabiertos.jcyl.es/api/explore/v2.1/catalog/datasets';

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

        // Si se pasa fichero local, importar directamente (modo legacy)
        if ($file) {
            return $this->importFile($file, $importer, $dryRun);
        }

        // Modo automático: descargar de API Opendatasoft
        $datasets = $this->option('dataset');
        if (empty($datasets)) {
            $datasets = array_keys(self::DATASETS);
        }

        $this->info($dryRun ? 'Modo DRY-RUN: no se insertaran datos.' : 'Importando contratos de Castilla y Leon...');

        $startTime = microtime(true);
        $fuenteDatosId = $this->resolveFuenteDatos($dryRun);
        if ($fuenteDatosId === false) {
            return self::FAILURE;
        }

        foreach ($datasets as $key) {
            if (! isset(self::DATASETS[$key])) {
                $this->warn("Dataset desconocido: {$key}. Opciones: ".implode(', ', array_keys(self::DATASETS)));

                continue;
            }

            $datasetId = self::DATASETS[$key];
            $this->newLine();
            $this->info("=== Dataset: {$key} ({$datasetId}) ===");

            $tempFile = $this->downloadDataset($datasetId, $key);
            if ($tempFile === null) {
                continue;
            }

            $this->importCsvFile($tempFile, $importer, $fuenteDatosId, $dryRun);
            @unlink($tempFile);
        }

        $duration = (int) (microtime(true) - $startTime);
        $this->logImport($fuenteDatosId, $duration, $dryRun, $datasets);
        $this->showSummary($duration, $dryRun);

        return self::SUCCESS;
    }

    private function importFile(string $file, ContratoImporter $importer, bool $dryRun): int
    {
        if (! file_exists($file)) {
            $this->error("Fichero no encontrado: {$file}");

            return self::FAILURE;
        }

        $this->info($dryRun ? 'Modo DRY-RUN: no se insertaran datos.' : 'Importando contratos de Castilla y Leon...');
        $this->info("Fichero: {$file}");

        $startTime = microtime(true);
        $fuenteDatosId = $this->resolveFuenteDatos($dryRun);
        if ($fuenteDatosId === false) {
            return self::FAILURE;
        }

        $this->importCsvFile($file, $importer, $fuenteDatosId, $dryRun);

        $duration = (int) (microtime(true) - $startTime);
        $this->logImport($fuenteDatosId, $duration, $dryRun, ['file']);
        $this->showSummary($duration, $dryRun);

        return self::SUCCESS;
    }

    private function downloadDataset(string $datasetId, string $key): ?string
    {
        $url = self::API_BASE."/{$datasetId}/exports/csv?delimiter=%3B&list_separator=%2C&quote_all=false&with_bom=false";

        $tempFile = storage_path("app/cyl_temp_{$key}.csv");
        $this->line("Descargando {$datasetId}...");

        try {
            $ch = curl_init($url);
            $fp = fopen($tempFile, 'w');
            curl_setopt_array($ch, [
                CURLOPT_FILE => $fp,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 600,
                CURLOPT_FAILONERROR => true,
            ]);
            $success = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            fclose($fp);

            if (! $success || $httpCode !== 200) {
                $this->error("Error descargando {$datasetId}: HTTP {$httpCode} {$curlError}");
                @unlink($tempFile);

                return null;
            }

            $fileSize = filesize($tempFile);
            $this->info('  Descargado: '.round($fileSize / 1048576, 1).' MB');

            return $tempFile;
        } catch (\Throwable $e) {
            $this->error("Error descargando {$datasetId}: {$e->getMessage()}");
            @unlink($tempFile);

            return null;
        }
    }

    private function importCsvFile(string $file, ContratoImporter $importer, ?int $fuenteDatosId, bool $dryRun): void
    {
        $chunkSize = (int) $this->option('chunk');
        $limit = (int) $this->option('limit');
        $parser = new CastillaYLeonParser;

        $reader = Reader::createFromPath($file, 'r');
        $reader->addStreamFilter('convert.iconv.UTF-8/UTF-8//IGNORE');
        $reader->setHeaderOffset(0);

        $firstLine = fgets(fopen($file, 'r'));
        if ($firstLine !== false) {
            if (str_contains($firstLine, '|')) {
                $reader->setDelimiter('|');
            } elseif (substr_count($firstLine, ';') > substr_count($firstLine, ',')) {
                $reader->setDelimiter(';');
            }
        }

        $headers = $reader->getHeader();
        $headers = array_map(fn ($h) => preg_replace('/^\xEF\xBB\xBF/', '', $h), $headers);

        $this->info('  Columnas: '.implode(', ', array_slice($headers, 0, 8)).'...');

        $records = $reader->getRecords();
        $totalRecords = $reader->count();

        $bar = $this->output->createProgressBar($totalRecords);
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% | %elapsed:6s% | Creados: %created% | Err: %errors%');
        $bar->setMessage('0', 'created');
        $bar->setMessage('0', 'errors');
        $bar->start();

        $chunk = [];
        foreach ($records as $record) {
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

    /**
     * @return int|null|false int = fuente ID, null = dry-run, false = error
     */
    private function resolveFuenteDatos(bool $dryRun): int|null|false
    {
        if ($dryRun) {
            return null;
        }

        $fuente = FuenteDatos::where('slug', 'cyl-contratos')->first();
        if (! $fuente) {
            $this->error('Fuente de datos "cyl-contratos" no encontrada. Ejecuta: php artisan db:seed');

            return false;
        }

        return $fuente->id;
    }

    /**
     * @param  array<int, array<string, string>>  $chunk
     */
    private function processChunk(
        array $chunk,
        CastillaYLeonParser $parser,
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

    private function logImport(?int $fuenteDatosId, int $duration, bool $dryRun, array $datasets): void
    {
        if ($dryRun || ! $fuenteDatosId) {
            return;
        }

        ImportLog::create([
            'fuente_datos_id' => $fuenteDatosId,
            'tipo' => 'sync-cyl',
            'procesados' => $this->processed,
            'nuevos' => $this->created,
            'actualizados' => $this->updated,
            'ignorados' => $this->skipped,
            'errores' => $this->errors,
            'duracion_segundos' => $duration,
            'notas' => 'CyL datasets: '.implode(', ', $datasets),
        ]);

        FuenteDatos::where('slug', 'cyl-contratos')
            ->update(['ultima_sincronizacion' => now()]);
    }

    private function showSummary(int $duration, bool $dryRun): void
    {
        $prefix = $dryRun ? '[DRY-RUN] ' : '';

        $this->newLine();
        $this->info("{$prefix}Resumen importacion Castilla y Leon:");
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
