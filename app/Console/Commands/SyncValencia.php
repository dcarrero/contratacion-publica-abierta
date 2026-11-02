<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Support\RegionalSyncCommand;
use App\Models\FuenteDatos;
use App\Services\ContratoImporter;
use App\Services\Regional\ValenciaParser;
use App\Support\SafeUrl;
use Illuminate\Support\Facades\Http;
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
     * Resource IDs por año en dadesobertes.gva.es. Solo de respaldo: el ID se pide primero
     * a la API de CKAN (resolveResourceId), porque GVA abre un dataset nuevo cada año y con
     * la tabla fija 2026 se quedó sin importar hasta octubre.
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
        2026 => '6b244ece-3ced-459f-a2a6-5cc4bfccd577',
    ];

    private const CKAN_PACKAGE_URL = 'https://dadesobertes.gva.es/api/3/action/package_show';

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
            $resource = $this->resolveResource($year);
            if ($resource === null) {
                $this->warn("No se encuentra el dataset de contratos de {$year} en dadesobertes.gva.es.");

                continue;
            }

            $this->newLine();
            $this->info("=== Año {$year} ===");

            // Primero el fichero estático (26 MB en segundos); el volcado del datastore va
            // tan lento que en 2026 no terminó en 10 minutos. Queda como respaldo.
            $dumpUrl = self::BASE_URL.'/'.$resource['id'];
            $tempFile = $resource['url'] !== null ? $this->downloadCsv($year, $resource['url']) : null;
            if ($tempFile === null) {
                $tempFile = $this->downloadCsv($year, $dumpUrl);
            }
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

    /**
     * Busca en CKAN el recurso CSV del dataset eco-gvo-contratos-{año}: su ID y la URL de
     * descarga directa. Si la API no responde, recurre a la tabla fija (solo ID).
     *
     * @return array{id: string, url: ?string}|null
     */
    private function resolveResource(int $year): ?array
    {
        try {
            $response = Http::timeout(30)
                ->withUserAgent('ContratacionAbierta/2.0 (transparencia)')
                ->get(self::CKAN_PACKAGE_URL, ['id' => "eco-gvo-contratos-{$year}"]);

            if ($response->successful() && $response->json('success') === true) {
                foreach ((array) $response->json('result.resources', []) as $resource) {
                    if (strtoupper((string) ($resource['format'] ?? '')) === 'CSV'
                        && ($resource['datastore_active'] ?? false)
                        && ! empty($resource['id'])) {
                        $url = (string) ($resource['url'] ?? '');

                        return [
                            'id' => (string) $resource['id'],
                            // Solo https y del dominio de la Generalitat; si no, se usa la
                            // URL de respaldo construida con BASE_URL y el id del recurso.
                            'url' => SafeUrl::isAllowedDownload($url, ['gva.es']) ? $url : null,
                        ];
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->warn("  CKAN no responde ({$e->getMessage()}); se usa la tabla de respaldo.");
        }

        return isset(self::RESOURCE_IDS[$year])
            ? ['id' => self::RESOURCE_IDS[$year], 'url' => null]
            : null;
    }

    private function downloadCsv(int $year, string $url): ?string
    {

        $tempFile = storage_path("app/val_temp_{$year}.csv");
        $this->line("Descargando año {$year}...");
        $this->line("  URL: {$url}");

        try {
            $ch = curl_init($url);
            $fp = fopen($tempFile, 'w');
            curl_setopt_array($ch, SafeUrl::curlOptions() + [
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
