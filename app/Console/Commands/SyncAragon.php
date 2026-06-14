<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Support\RegionalSyncCommand;
use App\Models\FuenteDatos;
use App\Services\ContratoImporter;
use App\Services\Regional\AragonParser;
use League\Csv\Reader;

class SyncAragon extends RegionalSyncCommand
{
    protected $signature = 'regional:sync-ara
        {file? : Ruta al fichero CSV local (si no se indica, descarga automáticamente)}
        {--year=* : Años a descargar (ej: --year=2025). Por defecto: año actual}
        {--chunk=500 : Registros por chunk}
        {--dry-run : Parsear y mostrar sin insertar datos}
        {--limit=0 : Limite de registros a procesar (0 = todos)}';

    protected $description = 'Importa contratos desde CSV dinamico del Gobierno de Aragon';

    private const CGI_BASE = 'https://serviciosciudadano.aragon.es/cgi-bin/AODB/BRSCGI';

    public function handle(ContratoImporter $importer): int
    {
        $file = $this->argument('file');
        $dryRun = (bool) $this->option('dry-run');

        if ($file) {
            return $this->importFile($file, $importer, $dryRun);
        }

        $years = $this->option('year');
        if (empty($years)) {
            $years = [(int) date('Y')];
        }
        $years = array_map('intval', $years);

        $this->info($dryRun ? 'Modo DRY-RUN: no se insertaran datos.' : 'Importando contratos de Aragon...');

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

            $this->importCsvFile($tempFile, $importer, $fuenteDatosId, $dryRun, $year);
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

        $this->info($dryRun ? 'Modo DRY-RUN: no se insertaran datos.' : 'Importando contratos de Aragon...');
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

        $this->importCsvFile($file, $importer, $fuenteDatosId, $dryRun, $year);

        $duration = (int) (microtime(true) - $startTime);
        $this->logImport($fuenteDatosId, $duration, $dryRun, [$year]);
        $this->showSummary($duration, $dryRun);

        return self::SUCCESS;
    }

    private function downloadCsv(int $year): ?string
    {
        $params = [
            'CMD' => 'VERLST',
            'BASE' => 'CONT',
            'DOCS' => '1-200000',
            'SEC' => 'OPENDATACONTCSV',
            'SORT' => '-EJER,CMEN',
            'SEPARADOR' => '',
        ];

        $url = self::CGI_BASE.'?'.http_build_query($params)
            .'&@EJER-GE='.$year
            .'&@EJER-LE='.$year;

        $tempFile = storage_path("app/ara_temp_{$year}.csv");
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

            // Convertir ISO-8859-1 a UTF-8
            return $this->ensureUtf8($tempFile, $year);
        } catch (\Throwable $e) {
            $this->error("Error descargando año {$year}: {$e->getMessage()}");
            @unlink($tempFile);

            return null;
        }
    }

    private function ensureUtf8(string $path, int $year): string
    {
        $sample = file_get_contents($path, false, null, 0, 4096);
        if ($sample === false) {
            return $path;
        }

        // Si json_encode falla, no es UTF-8
        if (json_encode($sample) === false) {
            $this->line('  Convirtiendo de ISO-8859-1 a UTF-8...');
            $utf8Path = storage_path("app/ara_temp_{$year}_utf8.csv");

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
        $parser = new AragonParser;

        $reader = Reader::createFromPath($file, 'r');
        $reader->setHeaderOffset(0);

        $firstLine = fgets(fopen($file, 'r'));
        if ($firstLine !== false) {
            if (substr_count($firstLine, ';') > substr_count($firstLine, ',')) {
                $reader->setDelimiter(';');
            }
        }

        $headers = $reader->getHeader();
        // Limpiar BOM
        $headers = array_map(fn ($h) => preg_replace('/^\xEF\xBB\xBF/', '', trim($h)), $headers);
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

    private function resolveFuenteDatos(bool $dryRun): int|null|false
    {
        if ($dryRun) {
            return null;
        }

        $fuente = FuenteDatos::where('slug', 'ara-contratos')->first();
        if (! $fuente) {
            $this->error('Fuente de datos "ara-contratos" no encontrada. Ejecuta: php artisan db:seed');

            return false;
        }

        return $fuente->id;
    }

    /**
     * @param  array<int, array<string, string>>  $chunk
     */
    private function processChunk(
        array $chunk,
        AragonParser $parser,
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
        return 'sync-ara';
    }

    protected function importRegionLabel(): string
    {
        return 'Aragon';
    }

    protected function fuenteSlug(): string
    {
        return 'ara-contratos';
    }
}
