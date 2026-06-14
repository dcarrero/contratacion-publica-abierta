<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Support\RegionalSyncCommand;
use App\Models\FuenteDatos;
use App\Services\ContratoImporter;
use App\Services\Regional\AsturiasParser;
use League\Csv\Reader;

class SyncAsturias extends RegionalSyncCommand
{
    protected $signature = 'regional:sync-ast
        {file? : Ruta al fichero CSV local (si no se indica, descarga automáticamente)}
        {--year=* : Años a descargar (ej: --year=2024). Por defecto: todos disponibles (2019-2024)}
        {--chunk=500 : Registros por chunk}
        {--dry-run : Parsear y mostrar sin insertar datos}
        {--limit=0 : Limite de registros a procesar (0 = todos)}';

    protected $description = 'Importa contratos desde CSV de contratación centralizada del Principado de Asturias';

    private const BASE_URL = 'https://descargas.asturias.es/asturias/opendata/SectorPublico/contratacion';

    private const AVAILABLE_YEARS = [2019, 2020, 2021, 2022, 2023, 2024];

    public function handle(ContratoImporter $importer): int
    {
        $file = $this->argument('file');
        $dryRun = (bool) $this->option('dry-run');

        if ($file) {
            return $this->importFile($file, $importer, $dryRun);
        }

        $years = $this->option('year');
        if (empty($years)) {
            $years = self::AVAILABLE_YEARS;
        }
        $years = array_map('intval', $years);

        $this->info($dryRun ? 'Modo DRY-RUN: no se insertaran datos.' : 'Importando contratos de Asturias...');

        $startTime = microtime(true);
        $fuenteDatosId = $this->resolveFuenteDatos($dryRun);
        if ($fuenteDatosId === false) {
            return self::FAILURE;
        }

        foreach ($years as $year) {
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

        $this->info($dryRun ? 'Modo DRY-RUN: no se insertaran datos.' : 'Importando contratos de Asturias...');
        $this->info("Fichero: {$file}");

        $startTime = microtime(true);
        $fuenteDatosId = $this->resolveFuenteDatos($dryRun);
        if ($fuenteDatosId === false) {
            return self::FAILURE;
        }

        // Detectar año del nombre del fichero
        $year = 0;
        if (preg_match('/(\d{4})/', basename($file), $m)) {
            $year = (int) $m[1];
        }

        // Convertir fichero local si es ISO-8859-1 con §
        $processedFile = $this->ensureUtf8ForLocal($file, $year ?: 0);
        $this->importCsvFile($processedFile, $importer, $fuenteDatosId, $dryRun);
        if ($processedFile !== $file) {
            @unlink($processedFile);
        }

        $duration = (int) (microtime(true) - $startTime);
        $this->logImport($fuenteDatosId, $duration, $dryRun, ['file']);
        $this->showSummary($duration, $dryRun);

        return self::SUCCESS;
    }

    private function downloadCsv(int $year): ?string
    {
        $url = self::BASE_URL."/dataset-contratacion-centralizada-{$year}.csv";
        $tempFile = storage_path("app/ast_temp_{$year}.csv");
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

            // Convertir de ISO-8859-1 a UTF-8
            return $this->ensureUtf8($tempFile, $year);
        } catch (\Throwable $e) {
            $this->error("Error descargando año {$year}: {$e->getMessage()}");
            @unlink($tempFile);

            return null;
        }
    }

    private function ensureUtf8(string $path, int $year): string
    {
        $this->line('  Convirtiendo de ISO-8859-1 a UTF-8 y reemplazando delimitador §→TAB...');
        $utf8Path = storage_path("app/ast_temp_{$year}_utf8.csv");

        $input = fopen($path, 'r');
        $output = fopen($utf8Path, 'w');

        while (($line = fgets($input)) !== false) {
            // Reemplazar § (0xA7 en ISO-8859-1) por TAB antes de convertir encoding
            $line = str_replace("\xA7", "\t", $line);
            fwrite($output, mb_convert_encoding($line, 'UTF-8', 'ISO-8859-1'));
        }

        fclose($input);
        fclose($output);
        @unlink($path);

        return $utf8Path;
    }

    private function ensureUtf8ForLocal(string $path, int $year): string
    {
        $sample = file_get_contents($path, false, null, 0, 4096);
        if ($sample === false) {
            return $path;
        }

        // Detectar si contiene § (ISO-8859-1: 0xA7) o es UTF-8 con § (0xC2 0xA7)
        $hasIsoSection = str_contains($sample, "\xA7") && ! str_contains($sample, "\xC2\xA7");
        $hasUtfSection = str_contains($sample, "\xC2\xA7");

        if (! $hasIsoSection && ! $hasUtfSection) {
            return $path; // No tiene §, asumimos TAB ya
        }

        $this->line('  Convirtiendo fichero local: encoding + delimitador §→TAB...');
        $utf8Path = storage_path("app/ast_temp_{$year}_local_utf8.csv");

        $input = fopen($path, 'r');
        $output = fopen($utf8Path, 'w');

        while (($line = fgets($input)) !== false) {
            if ($hasIsoSection) {
                $line = str_replace("\xA7", "\t", $line);
                $line = mb_convert_encoding($line, 'UTF-8', 'ISO-8859-1');
            } else {
                // UTF-8 con § multi-byte
                $line = str_replace('§', "\t", $line);
            }
            fwrite($output, $line);
        }

        fclose($input);
        fclose($output);

        return $utf8Path;
    }

    private function importCsvFile(string $file, ContratoImporter $importer, ?int $fuenteDatosId, bool $dryRun): void
    {
        $chunkSize = (int) $this->option('chunk');
        $limit = (int) $this->option('limit');
        $parser = new AsturiasParser;

        // Asturias usa § como delimitador, convertido a TAB durante ensureUtf8
        $reader = Reader::createFromPath($file, 'r');
        $reader->setHeaderOffset(0);
        $reader->setDelimiter("\t");

        $headers = $reader->getHeader();
        $trimmedHeaders = array_map('trim', $headers);

        // Desduplicar headers (OBJETO aparece 2 veces: col 6 y col 62)
        $seen = [];
        $uniqueHeaders = [];
        foreach ($trimmedHeaders as $h) {
            if (isset($seen[$h])) {
                $seen[$h]++;
                $uniqueHeaders[] = $h.'_'.$seen[$h];
            } else {
                $seen[$h] = 1;
                $uniqueHeaders[] = $h;
            }
        }

        $this->info('  Columnas: '.implode(', ', array_slice($uniqueHeaders, 0, 8)).'...');

        // Leer sin header offset para manejar duplicados manualmente
        $readerRaw = Reader::createFromPath($file, 'r');
        $readerRaw->setDelimiter("\t");
        $totalRecords = iterator_count($readerRaw->getRecords()) - 1; // -1 for header

        $readerRaw = Reader::createFromPath($file, 'r');
        $readerRaw->setDelimiter("\t");
        $rawRecords = $readerRaw->getRecords();
        $isFirstRow = true;

        $bar = $this->output->createProgressBar($totalRecords);
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% | %elapsed:6s% | Creados: %created% | Err: %errors%');
        $bar->setMessage('0', 'created');
        $bar->setMessage('0', 'errors');
        $bar->start();

        $chunk = [];
        foreach ($rawRecords as $row) {
            if ($isFirstRow) {
                $isFirstRow = false;

                continue; // Skip header row
            }

            // Map raw row values to unique headers
            $record = [];
            foreach ($uniqueHeaders as $i => $header) {
                $record[$header] = $row[$i] ?? '';
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

        $fuente = FuenteDatos::where('slug', 'ast-contratos')->first();
        if (! $fuente) {
            $this->error('Fuente de datos "ast-contratos" no encontrada. Ejecuta: php artisan db:seed');

            return false;
        }

        return $fuente->id;
    }

    /**
     * @param  array<int, array<string, string>>  $chunk
     */
    private function processChunk(
        array $chunk,
        AsturiasParser $parser,
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
                $data['tipo_registro'] = $data['es_menor'] ? 'contrato_menor' : 'licitacion';

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
        return 'sync-ast';
    }

    protected function importRegionLabel(): string
    {
        return 'Asturias';
    }

    protected function fuenteSlug(): string
    {
        return 'ast-contratos';
    }
}
