<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Support\RegionalSyncCommand;
use App\Models\FuenteDatos;
use App\Services\ContratoImporter;
use App\Services\Regional\ValenciaParser;
use League\Csv\Reader;

class SyncValencia extends RegionalSyncCommand
{
    protected $signature = 'regional:sync-val
        {file? : Ruta al fichero CSV local (si no se indica, descarga automáticamente)}
        {--year=* : Años a descargar (ej: --year=2025). Por defecto: todos}
        {--chunk=500 : Registros por chunk}
        {--dry-run : Parsear y mostrar sin insertar datos}
        {--limit=0 : Limite de registros a procesar (0 = todos)}';

    protected $description = 'Importa contratos desde CSV de datos abiertos de la Comunitat Valenciana';

    /**
     * Resource IDs por año en dadesobertes.gva.es
     */
    private const RESOURCE_IDS = [
        2018 => '89414f44-374c-403f-82ef-53cd160317a7',
        2019 => '08e05c03-5d5e-4c83-8629-e06156061482',
        2020 => '1f4c0be1-623b-4b5a-967d-46e51ff54446',
        2021 => '9001ba25-fc73-438f-8fbf-a14000733051',
        2022 => '299b6b0d-c6c9-463a-8689-39709eed6b10',
        2023 => 'a8b3aae7-ecb9-4508-80a6-c764805958a0',
        2024 => '69879ad2-ee19-426d-ad29-d1e5abe71c67',
        2025 => 'eb94c040-e7c0-4793-b921-144d2342d531',
    ];

    private const BASE_URL = 'https://dadesobertes.gva.es/datastore/dump';

    public function handle(ContratoImporter $importer): int
    {
        $file = $this->argument('file');
        $dryRun = (bool) $this->option('dry-run');

        if ($file) {
            return $this->importFile($file, $importer, $dryRun);
        }

        $years = $this->option('year');
        if (empty($years)) {
            $years = array_keys(self::RESOURCE_IDS);
        }
        $years = array_map('intval', $years);

        $this->info($dryRun ? 'Modo DRY-RUN: no se insertaran datos.' : 'Importando contratos de la Comunitat Valenciana...');

        $startTime = microtime(true);
        $fuenteDatosId = $this->resolveFuenteDatos($dryRun);
        if ($fuenteDatosId === false) {
            return self::FAILURE;
        }

        foreach ($years as $year) {
            if (! isset(self::RESOURCE_IDS[$year])) {
                $this->warn("No hay resource ID para el año {$year}. Años disponibles: ".implode(', ', array_keys(self::RESOURCE_IDS)));

                continue;
            }

            $this->newLine();
            $this->info("=== Año {$year} ===");

            $tempFile = $this->downloadCsv($year);
            if ($tempFile === null) {
                continue;
            }

            $this->importCsvFile($tempFile, $importer, $fuenteDatosId, $dryRun);
            @unlink($tempFile);
        }

        $duration = (int) (microtime(true) - $startTime);
        $this->logImport($fuenteDatosId, $duration, $dryRun, $years);
        $this->showSummary($duration, $dryRun);

        return self::SUCCESS;
    }

    private function importFile(string $file, ContratoImporter $importer, bool $dryRun): int
    {
        if (! file_exists($file)) {
            $this->error("Fichero no encontrado: {$file}");

            return self::FAILURE;
        }

        $this->info($dryRun ? 'Modo DRY-RUN: no se insertaran datos.' : 'Importando contratos de Valencia...');
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

    private function downloadCsv(int $year): ?string
    {
        $resourceId = self::RESOURCE_IDS[$year];
        $url = self::BASE_URL.'/'.$resourceId;

        $tempFile = storage_path("app/val_temp_{$year}.csv");
        $this->line("Descargando año {$year}...");
        $this->line("  URL: {$url}");

        try {
            $ch = curl_init($url);
            $fp = fopen($tempFile, 'w');
            curl_setopt_array($ch, [
                CURLOPT_FILE => $fp,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 600,
                CURLOPT_FAILONERROR => true,
                CURLOPT_USERAGENT => 'ContratacionAbierta/2.0 (transparencia)',
            ]);
            $success = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            fclose($fp);

            if (! $success || $httpCode !== 200) {
                $this->error("Error descargando año {$year}: HTTP {$httpCode} {$curlError}");
                @unlink($tempFile);

                return null;
            }

            $fileSize = filesize($tempFile);
            $this->info('  Descargado: '.round($fileSize / 1048576, 1).' MB');

            return $tempFile;
        } catch (\Throwable $e) {
            $this->error("Error descargando año {$year}: {$e->getMessage()}");
            @unlink($tempFile);

            return null;
        }
    }

    private function importCsvFile(string $file, ContratoImporter $importer, ?int $fuenteDatosId, bool $dryRun): void
    {
        $chunkSize = (int) $this->option('chunk');
        $limit = (int) $this->option('limit');
        $parser = new ValenciaParser;

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

        $fuente = FuenteDatos::where('slug', 'val-contratos')->first();
        if (! $fuente) {
            $this->error('Fuente de datos "val-contratos" no encontrada. Ejecuta: php artisan db:seed');

            return false;
        }

        return $fuente->id;
    }

    /**
     * @param  array<int, array<string, string>>  $chunk
     */
    private function processChunk(
        array $chunk,
        ValenciaParser $parser,
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

    protected function importTipo(): string
    {
        return 'sync-val';
    }

    protected function importRegionLabel(): string
    {
        return 'Comunitat Valenciana';
    }

    protected function fuenteSlug(): string
    {
        return 'val-contratos';
    }
}
