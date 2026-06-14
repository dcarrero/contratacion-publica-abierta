<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\FuenteDatos;
use App\Models\ImportLog;
use App\Services\ContratoImporter;
use App\Services\Regional\AndaluciaParser;
use Illuminate\Console\Command;
use League\Csv\Reader;

class SyncAndalucia extends Command
{
    protected $signature = 'regional:sync-anda
        {file? : Ruta al fichero CSV local (si no se indica, descarga automáticamente)}
        {--year=* : Años a descargar (ej: --year=2025). Por defecto: año actual}
        {--chunk=500 : Registros por chunk}
        {--dry-run : Parsear y mostrar sin insertar datos}
        {--limit=0 : Limite de registros a procesar (0 = todos)}';

    protected $description = 'Importa contratos menores desde CSV de la Junta de Andalucia (descarga automática o fichero local)';

    private const CKAN_API = 'https://www.juntadeandalucia.es/datosabiertos/portal/api/3/action/package_search';

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
            return $this->importFile($file, $importer, $dryRun, 0);
        }

        // Modo automático: buscar y descargar CSVs de la API CKAN
        $years = $this->option('year');
        if (empty($years)) {
            $years = [(int) date('Y')];
        }
        $years = array_map('intval', $years);

        $this->info($dryRun ? 'Modo DRY-RUN: no se insertaran datos.' : 'Importando contratos menores de Andalucia...');

        $startTime = microtime(true);
        $fuenteDatosId = $this->resolveFuenteDatos($dryRun);
        if ($fuenteDatosId === false) {
            return self::FAILURE;
        }

        foreach ($years as $year) {
            $this->newLine();
            $this->info("=== Año {$year} ===");

            $csvUrl = $this->findCsvUrl($year);
            if ($csvUrl === null) {
                $this->warn("No se encontro CSV para el año {$year} en la API CKAN");

                continue;
            }

            $this->line("URL: {$csvUrl}");

            $tempFile = $this->downloadCsv($csvUrl, $year);
            if ($tempFile === null) {
                continue;
            }

            $this->importCsvFile($tempFile, $importer, $fuenteDatosId, $dryRun, $year);
            @unlink($tempFile);
        }

        $duration = (int) (microtime(true) - $startTime);
        $this->logImport($fuenteDatosId, $duration, $dryRun, $years);
        $this->showSummary($duration, $dryRun);

        return self::SUCCESS;
    }

    private function importFile(string $file, ContratoImporter $importer, bool $dryRun, int $year): int
    {
        if (! file_exists($file)) {
            $this->error("Fichero no encontrado: {$file}");

            return self::FAILURE;
        }

        $this->info($dryRun ? 'Modo DRY-RUN: no se insertaran datos.' : 'Importando contratos de Andalucia...');
        $this->info("Fichero: {$file}");

        $startTime = microtime(true);
        $fuenteDatosId = $this->resolveFuenteDatos($dryRun);
        if ($fuenteDatosId === false) {
            return self::FAILURE;
        }

        // Detectar año del nombre del fichero si no se indica
        if ($year === 0 && preg_match('/(\d{4})/', basename($file), $m)) {
            $year = (int) $m[1];
        }

        $this->importCsvFile($file, $importer, $fuenteDatosId, $dryRun, $year);

        $duration = (int) (microtime(true) - $startTime);
        $this->logImport($fuenteDatosId, $duration, $dryRun, [$year]);
        $this->showSummary($duration, $dryRun);

        return self::SUCCESS;
    }

    private function findCsvUrl(int $year): ?string
    {
        try {
            $url = self::CKAN_API.'?'.http_build_query([
                'q' => "contratacion menor {$year}",
                'rows' => 10,
            ]);

            $response = file_get_contents($url);
            if ($response === false) {
                return null;
            }

            $data = json_decode($response, true);
            $results = $data['result']['results'] ?? [];

            foreach ($results as $dataset) {
                $title = $dataset['title'] ?? '';

                // Buscar dataset que contenga el año
                if (! str_contains($title, (string) $year)) {
                    continue;
                }
                if (! str_contains(mb_strtolower($title), 'menor')) {
                    continue;
                }

                // Buscar recurso CSV
                foreach ($dataset['resources'] ?? [] as $resource) {
                    $format = mb_strtoupper($resource['format'] ?? '');
                    if ($format === 'CSV') {
                        $resourceUrl = $resource['url'] ?? null;
                        if ($resourceUrl !== null) {
                            // La API CKAN devuelve URLs internas (gdc-pdpopendata-ckan.paas...)
                            // que no resuelven externamente. Reescribir al portal público.
                            $resourceUrl = str_replace(
                                'gdc-pdpopendata-ckan.paas.junta-andalucia.es',
                                'www.juntadeandalucia.es',
                                $resourceUrl
                            );
                        }

                        return $resourceUrl;
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->warn("Error consultando API CKAN: {$e->getMessage()}");
        }

        return null;
    }

    private function downloadCsv(string $url, int $year): ?string
    {
        $isZip = str_ends_with($url, '.zip');
        $tempFile = storage_path("app/anda_temp_{$year}".($isZip ? '.zip' : '.csv'));
        $this->line('Descargando...');

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
                $this->error("Error descargando: HTTP {$httpCode} {$curlError}");
                @unlink($tempFile);

                return null;
            }

            $fileSize = filesize($tempFile);
            $this->info('  Descargado: '.round($fileSize / 1048576, 1).' MB');

            // Si es ZIP, descomprimir
            if ($isZip) {
                return $this->extractZip($tempFile, $year);
            }

            // Verificar encoding y convertir a UTF-8 si necesario
            return $this->ensureUtf8($tempFile, $year);
        } catch (\Throwable $e) {
            $this->error("Error descargando: {$e->getMessage()}");
            @unlink($tempFile);

            return null;
        }
    }

    private function extractZip(string $zipPath, int $year): ?string
    {
        $zip = new \ZipArchive;
        if ($zip->open($zipPath) !== true) {
            $this->error('Error abriendo ZIP');
            @unlink($zipPath);

            return null;
        }

        $csvName = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (str_ends_with(mb_strtolower($name), '.csv')) {
                $csvName = $name;

                break;
            }
        }

        if ($csvName === null) {
            $this->error('No se encontro CSV dentro del ZIP');
            $zip->close();
            @unlink($zipPath);

            return null;
        }

        $extractPath = storage_path('app');
        $zip->extractTo($extractPath, $csvName);
        $zip->close();
        @unlink($zipPath);

        $csvPath = $extractPath.'/'.$csvName;
        $this->info("  Extraído: {$csvName}");

        return $this->ensureUtf8($csvPath, $year);
    }

    private function ensureUtf8(string $path, int $year): string
    {
        // Leer primeros bytes para detectar encoding
        $sample = file_get_contents($path, false, null, 0, 4096);
        if ($sample === false) {
            return $path;
        }

        // Si json_encode falla, no es UTF-8
        if (json_encode($sample) === false) {
            $this->line('  Convirtiendo de ISO-8859-1 a UTF-8...');
            $utf8Path = storage_path("app/anda_temp_{$year}_utf8.csv");

            $input = fopen($path, 'r');
            $output = fopen($utf8Path, 'w');

            while (($line = fgets($input)) !== false) {
                fwrite($output, mb_convert_encoding($line, 'UTF-8', 'ISO-8859-1'));
            }

            fclose($input);
            fclose($output);
            @unlink($path);

            return $utf8Path;
        }

        return $path;
    }

    private function importCsvFile(string $file, ContratoImporter $importer, ?int $fuenteDatosId, bool $dryRun, int $year): void
    {
        $chunkSize = (int) $this->option('chunk');
        $limit = (int) $this->option('limit');
        $parser = new AndaluciaParser;

        $reader = Reader::createFromPath($file, 'r');
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
        // Trim espacios de headers (CSV 2022 tiene " FECHA_ADJUDICACION" con espacio)
        $trimmedHeaders = array_map('trim', $headers);
        $needsHeaderFix = $headers !== $trimmedHeaders;
        if ($needsHeaderFix) {
            $this->line('  Headers con espacios detectados, se corregirán automáticamente.');
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
            // Trim keys si hay headers con espacios
            if ($needsHeaderFix) {
                $record = array_combine(array_map('trim', array_keys($record)), array_values($record));
            }
            if ($limit > 0 && $this->processed >= $limit) {
                break;
            }

            $chunk[] = $record;

            if (count($chunk) >= $chunkSize) {
                $this->processChunk($chunk, $parser, $importer, $fuenteDatosId, $dryRun, $year);
                $chunk = [];
                $bar->setMessage((string) $this->created, 'created');
                $bar->setMessage((string) $this->errors, 'errors');
                $bar->advance($chunkSize);
            }
        }

        if (count($chunk) > 0) {
            $this->processChunk($chunk, $parser, $importer, $fuenteDatosId, $dryRun, $year);
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

        $fuente = FuenteDatos::where('slug', 'anda-menores')->first();
        if (! $fuente) {
            $this->error('Fuente de datos "anda-menores" no encontrada. Ejecuta: php artisan db:seed');

            return false;
        }

        return $fuente->id;
    }

    /**
     * @param  array<int, array<string, string>>  $chunk
     */
    private function processChunk(
        array $chunk,
        AndaluciaParser $parser,
        ContratoImporter $importer,
        ?int $fuenteDatosId,
        bool $dryRun,
        int $year
    ): void {
        foreach ($chunk as $record) {
            $this->processed++;

            try {
                $data = $parser->parse($record, $year);

                if ($data === null) {
                    $this->skipped++;

                    continue;
                }

                if (empty($data['nif_adjudicatario'])) {
                    $this->noNif++;
                }

                $data['fuente_datos_id'] = $fuenteDatosId;
                $data['tipo_registro'] = 'contrato_menor';

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

    private function logImport(?int $fuenteDatosId, int $duration, bool $dryRun, array $years): void
    {
        if ($dryRun || ! $fuenteDatosId) {
            return;
        }

        ImportLog::create([
            'fuente_datos_id' => $fuenteDatosId,
            'tipo' => 'sync-anda',
            'procesados' => $this->processed,
            'nuevos' => $this->created,
            'actualizados' => $this->updated,
            'ignorados' => $this->skipped,
            'errores' => $this->errors,
            'duracion_segundos' => $duration,
            'notas' => 'Andalucia años: '.implode(', ', $years),
        ]);

        FuenteDatos::where('slug', 'anda-menores')
            ->update(['ultima_sincronizacion' => now()]);
    }

    private function showSummary(int $duration, bool $dryRun): void
    {
        $prefix = $dryRun ? '[DRY-RUN] ' : '';

        $this->newLine();
        $this->info("{$prefix}Resumen importacion Andalucia:");
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
