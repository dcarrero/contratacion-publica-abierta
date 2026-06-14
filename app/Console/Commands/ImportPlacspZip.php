<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Contrato;
use App\Models\FuenteDatos;
use App\Models\ImportLog;
use App\Services\ContratoImporter;
use App\Services\Placsp\PlacspEntryParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class ImportPlacspZip extends Command
{
    protected $signature = 'placsp:import-zip
        {type : Tipo de datos: licitaciones o menores}
        {period : Periodo YYYY (anual), YYYYMM (mensual) o rango YYYYMM-YYYYMM / YYYY-YYYY}
        {--dry-run : Parsear y mostrar sin insertar datos}
        {--keep-zips : No borrar los ZIPs descargados}
        {--only-new : Solo crear contratos nuevos, no actualizar existentes}
        {--ccaa= : Filtrar por CCAA (codigo NUTS2, ej: ES42)}';

    protected $description = 'Importa contratos desde ZIPs de datos abiertos PLACSP';

    private const TYPES = [
        'licitaciones' => [
            'base_url' => 'https://contrataciondelsectorpublico.gob.es/sindicacion/sindicacion_643/licitacionesPerfilesContratanteCompleto3_',
            'fuente_slug' => 'placsp-licitaciones',
            'es_menor' => false,
            'tipo_registro' => 'licitacion',
            'prefix' => 'licitaciones',
        ],
        'menores' => [
            'base_url' => 'https://contrataciondelsectorpublico.gob.es/sindicacion/sindicacion_1143/contratosMenoresPerfilesContratantes_',
            'fuente_slug' => 'placsp-menores',
            'es_menor' => true,
            'tipo_registro' => 'menor',
            'prefix' => 'menores',
        ],
        'agregacion' => [
            'base_url' => 'https://contrataciondelsectorpublico.gob.es/sindicacion/sindicacion_1044/PlataformasAgregadasSinMenores_',
            'fuente_slug' => 'placsp-agregacion',
            'es_menor' => false,
            'tipo_registro' => 'agregacion',
            'prefix' => 'agregacion',
        ],
        'emp' => [
            'base_url' => 'https://contrataciondelsectorpublico.gob.es/sindicacion/sindicacion_1383/EMP_SectorPublico_',
            'fuente_slug' => 'placsp-emp',
            'es_menor' => false,
            'tipo_registro' => 'emp',
            'prefix' => 'emp',
        ],
        'cpm' => [
            'base_url' => 'https://contrataciondelsectorpublico.gob.es/sindicacion/sindicacion_1403/CPM_SectorPublico_',
            'fuente_slug' => 'placsp-cpm',
            'es_menor' => false,
            'tipo_registro' => 'cpm',
            'prefix' => 'cpm',
        ],
    ];

    private int $processed = 0;

    private int $created = 0;

    private int $updated = 0;

    private int $skipped = 0;

    private int $errors = 0;

    public function handle(ContratoImporter $importer, PlacspEntryParser $parser): int
    {
        $type = $this->argument('type');

        if (! isset(self::TYPES[$type])) {
            $this->error("Tipo inválido: {$type}. Usa: ".implode(', ', array_keys(self::TYPES)));

            return self::FAILURE;
        }

        $config = self::TYPES[$type];
        $dryRun = (bool) $this->option('dry-run');
        $keepZips = (bool) $this->option('keep-zips');
        $onlyNew = (bool) $this->option('only-new');
        $periods = $this->resolvePeriods($this->argument('period'));

        if (empty($periods)) {
            $this->error('Periodo inválido. Usa YYYYMM o YYYYMM-YYYYMM');

            return self::FAILURE;
        }

        $this->info($dryRun
            ? 'Modo DRY-RUN: no se insertarán datos.'
            : "Importando {$type} desde ZIPs PLACSP...");
        if ($onlyNew) {
            $this->info('Modo --only-new: solo se crearán contratos que no existan en BD.');
        }
        $this->info('Periodos: '.implode(', ', $periods));

        $startTime = microtime(true);

        $fuenteDatosId = null;
        if (! $dryRun) {
            $fuente = FuenteDatos::where('slug', $config['fuente_slug'])->first();
            if (! $fuente) {
                $this->error("Fuente de datos \"{$config['fuente_slug']}\" no encontrada. Ejecuta: php artisan db:seed");

                return self::FAILURE;
            }
            $fuenteDatosId = $fuente->id;
        }

        $downloadDir = storage_path('app/placsp-zips');
        if (! is_dir($downloadDir)) {
            mkdir($downloadDir, 0755, true);
        }

        foreach ($periods as $period) {
            $this->processPeriod($period, $downloadDir, $config, $parser, $importer, $fuenteDatosId, $dryRun, $keepZips, $onlyNew);
        }

        $duration = (int) (microtime(true) - $startTime);

        if (! $dryRun && $fuenteDatosId) {
            ImportLog::create([
                'fuente_datos_id' => $fuenteDatosId,
                'tipo' => "import-{$config['prefix']}-zip",
                'procesados' => $this->processed,
                'nuevos' => $this->created,
                'actualizados' => $this->updated,
                'ignorados' => $this->skipped,
                'errores' => $this->errors,
                'duracion_segundos' => $duration,
                'notas' => 'ZIPs: '.implode(', ', $periods),
            ]);
        }

        $this->showSummary($type, $duration, $dryRun);

        return self::SUCCESS;
    }

    private function processPeriod(
        string $period,
        string $downloadDir,
        array $config,
        PlacspEntryParser $parser,
        ContratoImporter $importer,
        ?int $fuenteDatosId,
        bool $dryRun,
        bool $keepZips,
        bool $onlyNew
    ): void {
        $this->newLine();
        $this->info("=== Periodo {$period} ===");

        $prefix = $config['prefix'];
        $zipPath = "{$downloadDir}/{$prefix}_{$period}.zip";
        $extractDir = "{$downloadDir}/{$prefix}_{$period}";

        // 1. Descargar ZIP
        if (! file_exists($zipPath) || filesize($zipPath) < 1024) {
            @unlink($zipPath);
            $url = $config['base_url']."{$period}.zip";
            $this->line("  Descargando: {$url}");

            if (! $this->downloadZip($url, $zipPath)) {
                $this->warn("  No se pudo descargar — saltando periodo {$period}");

                return;
            }

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
            $this->processAtomFile($atomFile, $config, $parser, $importer, $fuenteDatosId, $dryRun, $onlyNew);

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
        array $config,
        PlacspEntryParser $parser,
        ContratoImporter $importer,
        ?int $fuenteDatosId,
        bool $dryRun,
        bool $onlyNew
    ): void {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_file($path);
        libxml_clear_errors();

        if ($xml === false) {
            $this->errors++;

            return;
        }

        $ccaaFilter = $this->option('ccaa');

        foreach ($xml->entry as $entry) {
            $this->processed++;

            try {
                $data = $parser->parse($entry);

                if ($data === null || empty($data['nif_organo'])) {
                    $this->skipped++;

                    continue;
                }

                // Filtro opcional por CCAA
                if ($ccaaFilter && ! str_starts_with($data['nuts'] ?? '', $ccaaFilter)) {
                    $this->skipped++;

                    continue;
                }

                // En modo only-new, saltar si el contrato ya existe
                if ($onlyNew && ! empty($data['placsp_id'])) {
                    if (Contrato::where('placsp_id', $data['placsp_id'])->exists()) {
                        $this->skipped++;

                        continue;
                    }
                }

                $data['es_menor'] = $config['es_menor'];
                $data['tipo_registro'] = $parser->lastNifMatchedByName
                    ? $config['tipo_registro'].'-auto'
                    : $config['tipo_registro'];
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

            // Rango anual YYYY-YYYY → expandir a periodos anuales
            if (preg_match('/^\d{4}$/', $start) && preg_match('/^\d{4}$/', $end)) {
                return $this->expandYearRange($start, $end);
            }

            return $this->expandRange($start, $end);
        }

        // Periodo anual YYYY
        if (preg_match('/^\d{4}$/', $input)) {
            return [$input];
        }

        // Periodo mensual YYYYMM
        if (preg_match('/^\d{6}$/', $input)) {
            return [$input];
        }

        return [];
    }

    /**
     * @return string[]
     */
    private function expandYearRange(string $startYear, string $endYear): array
    {
        $start = (int) $startYear;
        $end = (int) $endYear;

        if ($start > $end) {
            return [];
        }

        $periods = [];
        for ($y = $start; $y <= $end; $y++) {
            $periods[] = (string) $y;
        }

        return $periods;
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

    private function downloadZip(string $url, string $path): bool
    {
        // Intento 1: curl (streaming directo a disco, sin cargar en memoria)
        $escapedUrl = escapeshellarg($url);
        $escapedPath = escapeshellarg($path);
        $userAgent = escapeshellarg('ContratacionAbierta/2.0 (transparencia; +https://github.com/dcarrero/contratacion-publica-clm-es)');
        $cmd = "curl -sL --max-time 600 -A {$userAgent} -o {$escapedPath} {$escapedUrl}";
        exec($cmd, $output, $exitCode);

        if ($exitCode === 0 && file_exists($path) && filesize($path) > 1024) {
            if (str_starts_with((string) file_get_contents($path, false, null, 0, 4), 'PK')) {
                return true;
            }
        }

        // Intento 2: HTTP client con sink (streaming a disco)
        @unlink($path);
        $this->line('  Reintentando con HTTP sink...');

        try {
            $response = Http::withOptions([
                'sink' => $path,
            ])
                ->timeout(600)
                ->withHeaders([
                    'User-Agent' => 'ContratacionAbierta/2.0 (transparencia; +https://github.com/dcarrero/contratacion-publica-clm-es)',
                    'Accept' => 'application/zip, application/octet-stream, */*',
                ])
                ->get($url);

            if ($response->successful() && file_exists($path) && filesize($path) > 1024) {
                if (str_starts_with((string) file_get_contents($path, false, null, 0, 4), 'PK')) {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            $this->warn("  Error HTTP: {$e->getMessage()}");
        }

        @unlink($path);

        return false;
    }

    private function cleanDirectory(string $dir): void
    {
        $files = glob("{$dir}/*");
        foreach ($files as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }

    private function showSummary(string $type, int $duration, bool $dryRun): void
    {
        $prefix = $dryRun ? '[DRY-RUN] ' : '';

        $this->newLine();
        $this->info("{$prefix}Resumen importacion {$type} desde ZIPs:");
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
