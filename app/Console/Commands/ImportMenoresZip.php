<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\FuenteDatos;
use App\Models\ImportLog;
use App\Services\ContratoImporter;
use App\Services\Placsp\PlacspEntryParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class ImportMenoresZip extends Command
{
    protected $signature = 'placsp:import-menores-zip
        {period : Periodo YYYYMM (ej: 202401) o rango YYYYMM-YYYYMM (ej: 202401-202412)}
        {--dry-run : Parsear y mostrar sin insertar datos}
        {--keep-zips : No borrar los ZIPs descargados}';

    protected $description = 'Importa contratos menores desde ZIPs mensuales de datos abiertos PLACSP';

    private const BASE_URL = 'https://contrataciondelestado.es/sindicacion/sindicacion_1143/contratosMenoresPerfilesContratantes_';

    private int $processed = 0;

    private int $created = 0;

    private int $updated = 0;

    private int $skipped = 0;

    private int $errors = 0;

    public function handle(ContratoImporter $importer, PlacspEntryParser $parser): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $keepZips = (bool) $this->option('keep-zips');
        $periods = $this->resolvePeriods($this->argument('period'));

        if (empty($periods)) {
            $this->error('Periodo inválido. Usa YYYYMM o YYYYMM-YYYYMM');

            return self::FAILURE;
        }

        $this->info($dryRun ? 'Modo DRY-RUN: no se insertarán datos.' : 'Importando contratos menores desde ZIPs PLACSP...');
        $this->info('Periodos: '.implode(', ', $periods));

        $startTime = microtime(true);

        $fuenteDatosId = null;
        if (! $dryRun) {
            $fuente = FuenteDatos::where('slug', 'placsp-menores')->first();
            if (! $fuente) {
                $this->error('Fuente de datos "placsp-menores" no encontrada. Ejecuta: php artisan db:seed');

                return self::FAILURE;
            }
            $fuenteDatosId = $fuente->id;
        }

        $downloadDir = storage_path('app/placsp-zips');
        if (! is_dir($downloadDir)) {
            mkdir($downloadDir, 0755, true);
        }

        foreach ($periods as $period) {
            $this->processPeriod($period, $downloadDir, $parser, $importer, $fuenteDatosId, $dryRun, $keepZips);
        }

        $duration = (int) (microtime(true) - $startTime);

        if (! $dryRun && $fuenteDatosId) {
            ImportLog::create([
                'fuente_datos_id' => $fuenteDatosId,
                'tipo' => 'import-menores-zip',
                'procesados' => $this->processed,
                'nuevos' => $this->created,
                'actualizados' => $this->updated,
                'ignorados' => $this->skipped,
                'errores' => $this->errors,
                'duracion_segundos' => $duration,
                'notas' => 'ZIPs: '.implode(', ', $periods),
            ]);
        }

        $this->showSummary($duration, $dryRun);

        return self::SUCCESS;
    }

    private function processPeriod(
        string $period,
        string $downloadDir,
        PlacspEntryParser $parser,
        ContratoImporter $importer,
        ?int $fuenteDatosId,
        bool $dryRun,
        bool $keepZips
    ): void {
        $this->newLine();
        $this->info("=== Periodo {$period} ===");

        $zipPath = "{$downloadDir}/menores_{$period}.zip";
        $extractDir = "{$downloadDir}/menores_{$period}";

        // 1. Descargar ZIP
        if (! file_exists($zipPath)) {
            $url = self::BASE_URL."{$period}.zip";
            $this->line("  Descargando: {$url}");

            $response = Http::timeout(120)
                ->get($url);

            if (! $response->successful()) {
                $this->warn("  HTTP {$response->status()} — saltando periodo {$period}");

                return;
            }

            file_put_contents($zipPath, $response->body());
            $sizeMB = round(filesize($zipPath) / 1048576, 1);
            $this->line("  Descargado: {$sizeMB} MB");
        } else {
            $this->line('  ZIP ya descargado, usando caché local.');
        }

        // 2. Extraer ZIP
        if (! is_dir($extractDir)) {
            mkdir($extractDir, 0755, true);
        }

        $zip = new \ZipArchive;
        if ($zip->open($zipPath) !== true) {
            $this->error("  No se pudo abrir el ZIP: {$zipPath}");

            return;
        }

        $fileCount = $zip->numFiles;
        $this->line("  Ficheros Atom en ZIP: {$fileCount}");
        $zip->extractTo($extractDir);
        $zip->close();

        // 3. Procesar cada fichero Atom
        $atomFiles = glob("{$extractDir}/*.atom");
        sort($atomFiles);

        foreach ($atomFiles as $i => $atomFile) {
            $this->processAtomFile($atomFile, $parser, $importer, $fuenteDatosId, $dryRun);

            if (($i + 1) % 10 === 0 || $i === count($atomFiles) - 1) {
                $this->line('  Ficheros procesados: '.($i + 1)."/{$fileCount} — entries: {$this->processed} ({$this->created} nuevos)");
            }
        }

        // 4. Limpiar
        $this->cleanDirectory($extractDir);
        if (! $keepZips) {
            @unlink($zipPath);
        }
    }

    private function processAtomFile(
        string $path,
        PlacspEntryParser $parser,
        ContratoImporter $importer,
        ?int $fuenteDatosId,
        bool $dryRun
    ): void {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_file($path);
        libxml_clear_errors();

        if ($xml === false) {
            $this->errors++;

            return;
        }

        foreach ($xml->entry as $entry) {
            $this->processed++;

            try {
                $data = $parser->parse($entry);

                if ($data === null || empty($data['nif_organo'])) {
                    $this->skipped++;

                    continue;
                }

                $data['es_menor'] = true;
                $data['tipo_registro'] = 'menor';
                $data['fuente_datos_id'] = $fuenteDatosId;

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
            }
        }
    }

    /**
     * @return string[]
     */
    private function resolvePeriods(string $input): array
    {
        if (str_contains($input, '-')) {
            [$start, $end] = explode('-', $input, 2);

            return $this->expandRange($start, $end);
        }

        if (preg_match('/^\d{6}$/', $input)) {
            return [$input];
        }

        return [];
    }

    /**
     * @return string[]
     */
    private function expandRange(string $start, string $end): array
    {
        if (! preg_match('/^\d{6}$/', $start) || ! preg_match('/^\d{6}$/', $end)) {
            return [];
        }

        $periods = [];
        $year = (int) substr($start, 0, 4);
        $month = (int) substr($start, 4, 2);
        $endYear = (int) substr($end, 0, 4);
        $endMonth = (int) substr($end, 4, 2);

        while ($year < $endYear || ($year === $endYear && $month <= $endMonth)) {
            $periods[] = sprintf('%04d%02d', $year, $month);
            $month++;
            if ($month > 12) {
                $month = 1;
                $year++;
            }
        }

        return $periods;
    }

    private function cleanDirectory(string $dir): void
    {
        $files = glob("{$dir}/*");
        foreach ($files as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }

    private function showSummary(int $duration, bool $dryRun): void
    {
        $prefix = $dryRun ? '[DRY-RUN] ' : '';

        $this->newLine();
        $this->info("{$prefix}Resumen importacion menores desde ZIPs:");
        $this->table(
            ['Metrica', 'Valor'],
            [
                ['Entries procesados', number_format($this->processed)],
                ['Nuevos', number_format($this->created)],
                ['Actualizados', number_format($this->updated)],
                ['Sin cambios', number_format($this->skipped)],
                ['Errores', number_format($this->errors)],
                ['Duracion', gmdate('H:i:s', $duration)],
            ]
        );
    }
}
