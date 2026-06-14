<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Support\RegionalSyncCommand;
use App\Models\FuenteDatos;
use App\Services\ContratoImporter;
use App\Services\Regional\MurciaParser;
use League\Csv\Reader;

class SyncMurcia extends RegionalSyncCommand
{
    protected $signature = 'regional:sync-mur
        {file? : Ruta al fichero CSV local (si no se indica, descarga automáticamente)}
        {--year=* : Años a descargar (ej: --year=2023). Por defecto: todos disponibles}
        {--tipo=all : Tipo de contratos: "mayor" (excl. menores), "menor", "all"}
        {--chunk=500 : Registros por chunk}
        {--dry-run : Parsear y mostrar sin insertar datos}
        {--limit=0 : Limite de registros a procesar (0 = todos)}';

    protected $description = 'Importa contratos desde CSV de datos abiertos de la Region de Murcia (CARM)';

    /**
     * Contratos mayores (excl. menores): desde 2019.
     * URL: https://datosabiertos.carm.es/odata/transparencia/contratosOD{AÑO}.csv
     */
    private const BASE_URL_MAYOR = 'https://datosabiertos.carm.es/odata/transparencia/contratosOD';

    /**
     * Contratos menores: desde 2022.
     * URL: https://datosabiertos.carm.es/odata/Hacienda/CONTRA_ContratosMenores_{AÑO}.csv
     */
    private const BASE_URL_MENOR = 'https://datosabiertos.carm.es/odata/Hacienda/CONTRA_ContratosMenores_';

    private const AVAILABLE_YEARS_MAYOR = [2019, 2020, 2021, 2022, 2023];

    private const AVAILABLE_YEARS_MENOR = [2022, 2023, 2024, 2025];

    public function handle(ContratoImporter $importer): int
    {
        $file = $this->argument('file');
        $dryRun = (bool) $this->option('dry-run');
        $tipo = (string) $this->option('tipo');

        if (! in_array($tipo, ['mayor', 'menor', 'all'], true)) {
            $this->error('--tipo debe ser: mayor, menor, all');

            return self::FAILURE;
        }

        $this->info($dryRun ? 'Modo DRY-RUN: no se insertaran datos.' : 'Importando contratos de Murcia (CARM)...');

        if ($file) {
            return $this->importLocalFile($file, $importer, $dryRun, $tipo);
        }

        $startTime = microtime(true);
        $fuenteDatosId = $this->resolveFuenteDatos($dryRun);
        if ($fuenteDatosId === false) {
            return self::FAILURE;
        }

        $years = $this->option('year');
        $processedYears = [];

        $parser = new MurciaParser;

        // Importar contratos mayores
        if (in_array($tipo, ['mayor', 'all'], true)) {
            $yearsMayor = empty($years) ? self::AVAILABLE_YEARS_MAYOR : array_map('intval', $years);

            foreach ($yearsMayor as $year) {
                $this->newLine();
                $this->info("=== Contratos mayores — Año {$year} ===");

                $tempFile = $this->downloadCsv(self::BASE_URL_MAYOR."{$year}.csv", "mur_mayor_{$year}");
                if ($tempFile === null) {
                    continue;
                }

                $this->importCsvFile($tempFile, $parser, 'mayor', $importer, $fuenteDatosId, $dryRun);
                @unlink($tempFile);
                $processedYears[] = "mayor-{$year}";
            }
        }

        // Importar contratos menores
        if (in_array($tipo, ['menor', 'all'], true)) {
            $yearsMenor = empty($years) ? self::AVAILABLE_YEARS_MENOR : array_map('intval', $years);

            foreach ($yearsMenor as $year) {
                $this->newLine();
                $this->info("=== Contratos menores — Año {$year} ===");

                $tempFile = $this->downloadCsv(self::BASE_URL_MENOR."{$year}.csv", "mur_menor_{$year}");
                if ($tempFile === null) {
                    continue;
                }

                $this->importCsvFile($tempFile, $parser, 'menor', $importer, $fuenteDatosId, $dryRun);
                @unlink($tempFile);
                $processedYears[] = "menor-{$year}";
            }
        }

        $duration = (int) (microtime(true) - $startTime);
        $this->logImport($fuenteDatosId, $duration, $dryRun, $processedYears);
        $this->showSummary($duration, $dryRun);

        return self::SUCCESS;
    }

    private function importLocalFile(string $file, ContratoImporter $importer, bool $dryRun, string $tipo): int
    {
        if (! file_exists($file)) {
            $this->error("Fichero no encontrado: {$file}");

            return self::FAILURE;
        }

        $this->info("Fichero: {$file}");

        // Detectar tipo desde nombre de fichero si es 'all'
        if ($tipo === 'all') {
            if (str_contains(basename($file), 'Menor') || str_contains(basename($file), 'menor')) {
                $tipo = 'menor';
            } else {
                $tipo = 'mayor';
            }
        }

        $this->info("Tipo detectado: {$tipo}");

        $startTime = microtime(true);
        $fuenteDatosId = $this->resolveFuenteDatos($dryRun);
        if ($fuenteDatosId === false) {
            return self::FAILURE;
        }

        $parser = new MurciaParser;
        $this->importCsvFile($file, $parser, $tipo, $importer, $fuenteDatosId, $dryRun);

        $duration = (int) (microtime(true) - $startTime);
        $this->logImport($fuenteDatosId, $duration, $dryRun, ['file']);
        $this->showSummary($duration, $dryRun);

        return self::SUCCESS;
    }

    private function downloadCsv(string $url, string $tempName): ?string
    {
        $tempFile = storage_path("app/{$tempName}.csv");
        $this->line("Descargando: {$url}");

        try {
            $ch = curl_init($url);
            $fp = fopen($tempFile, 'w');
            curl_setopt_array($ch, [
                CURLOPT_FILE => $fp,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 300,
                CURLOPT_FAILONERROR => true,
                CURLOPT_USERAGENT => 'ContratacionAbierta/2.0 (transparencia)',
            ]);
            $success = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            fclose($fp);

            if (! $success || $httpCode !== 200) {
                $this->warn("  HTTP {$httpCode}: {$curlError} — saltando");
                @unlink($tempFile);

                return null;
            }

            $fileSize = filesize($tempFile);
            $this->info('  Descargado: '.round($fileSize / 1024, 0).' KB');

            return $tempFile;
        } catch (\Throwable $e) {
            $this->error("Error descargando: {$e->getMessage()}");
            @unlink($tempFile);

            return null;
        }
    }

    private function importCsvFile(
        string $file,
        MurciaParser $parser,
        string $tipo,
        ContratoImporter $importer,
        ?int $fuenteDatosId,
        bool $dryRun
    ): void {
        $chunkSize = (int) $this->option('chunk');
        $limit = (int) $this->option('limit');

        try {
            $reader = Reader::createFromPath($file, 'r');
            $reader->setHeaderOffset(0);
            $reader->setDelimiter(',');
        } catch (\Throwable $e) {
            $this->error("  Error abriendo CSV: {$e->getMessage()}");

            return;
        }

        $totalRecords = iterator_count($reader->getRecords());

        // Re-open for iteration (iterator already consumed)
        $reader = Reader::createFromPath($file, 'r');
        $reader->setHeaderOffset(0);
        $reader->setDelimiter(',');

        $bar = $this->output->createProgressBar($totalRecords);
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% | %elapsed:6s% | Creados: %created% | Err: %errors%');
        $bar->setMessage('0', 'created');
        $bar->setMessage('0', 'errors');
        $bar->start();

        $chunk = [];
        foreach ($reader->getRecords() as $record) {
            if ($limit > 0 && $this->processed >= $limit) {
                break;
            }

            $chunk[] = $record;

            if (count($chunk) >= $chunkSize) {
                $this->processChunk($chunk, $parser, $tipo, $importer, $fuenteDatosId, $dryRun);
                $chunk = [];
                $bar->setMessage((string) $this->created, 'created');
                $bar->setMessage((string) $this->errors, 'errors');
                $bar->advance($chunkSize);
            }
        }

        if (count($chunk) > 0) {
            $this->processChunk($chunk, $parser, $tipo, $importer, $fuenteDatosId, $dryRun);
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

        $fuente = FuenteDatos::where('slug', $this->fuenteSlug())->first();
        if (! $fuente) {
            $this->error('Fuente de datos "mur-contratos" no encontrada. Ejecuta: php artisan db:seed');

            return false;
        }

        return $fuente->id;
    }

    /**
     * @param  array<int, array<string, string>>  $chunk
     */
    private function processChunk(
        array $chunk,
        MurciaParser $parser,
        string $tipo,
        ContratoImporter $importer,
        ?int $fuenteDatosId,
        bool $dryRun
    ): void {
        foreach ($chunk as $record) {
            $this->processed++;

            try {
                $data = $tipo === 'menor'
                    ? $parser->parseMenor($record)
                    : $parser->parseMayor($record);

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
        return 'sync-mur';
    }

    protected function importRegionLabel(): string
    {
        return 'Murcia (CARM)';
    }

    protected function fuenteSlug(): string
    {
        return 'mur-contratos';
    }
}
