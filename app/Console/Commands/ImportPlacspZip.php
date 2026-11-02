<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Contrato;
use App\Models\FuenteDatos;
use App\Models\ImportLog;
use App\Services\ContratoImporter;
use App\Services\Placsp\PlacspEntryParser;
use App\Support\SafeUrl;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Http;

class ImportPlacspZip extends Command
{
    protected $signature = 'placsp:import-zip
        {type : Tipo de datos: licitaciones o menores}
        {period : Periodo YYYY (anual), YYYYMM (mensual) o rango YYYYMM-YYYYMM / YYYY-YYYY}
        {--dry-run : Parsear y mostrar sin insertar datos}
        {--keep-zips : No borrar los ZIPs descargados}
        {--only-new : Solo crear contratos nuevos, no actualizar existentes}
        {--force-download : Ignorar la cache local y volver a descargar siempre el ZIP}
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

    private const USER_AGENT = 'ContratacionAbierta/2.0 (transparencia; +https://github.com/dcarrero/contratacion-publica-clm-es)';

    /**
     * Horas que se da por buena la cache de un periodo abierto cuando NO se ha podido
     * revalidar contra el remoto (sin red, o el TLS de la CA espanola fallando fuera del
     * contenedor). Es el plan B, no el mecanismo principal.
     */
    private const CACHE_MAX_AGE_HOURS = 12;

    /** El ZIP local sirve tal cual. */
    private const CACHE_HIT = 'hit';

    /** No hay ZIP local utilizable. */
    private const CACHE_MISS = 'miss';

    /** Hay ZIP local pero el remoto ha cambiado desde que se descargo. */
    private const CACHE_STALE = 'stale';

    /** El usuario ha pedido --force-download. */
    private const CACHE_FORCED = 'forced';

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

        // 1. Descargar ZIP (revalidando la caché local si el periodo sigue abierto)
        $url = $config['base_url']."{$period}.zip";
        $cacheState = $this->resolveCacheState($zipPath, $period, $url);

        if ($cacheState === self::CACHE_HIT) {
            $this->line('  Caché local válida: se reutiliza el ZIP ya descargado.');
        } else {
            if ($cacheState === self::CACHE_STALE) {
                $this->warn('  Caché local obsoleta (el remoto ha cambiado): se descarta y se descarga de nuevo.');
            } elseif ($cacheState === self::CACHE_FORCED) {
                $this->line('  --force-download: se ignora la caché local.');
            } else {
                $this->line('  Sin caché local: descarga nueva.');
            }

            @unlink($zipPath);
            $this->line("  Descargando: {$url}");

            if (! $this->downloadZip($url, $zipPath)) {
                $this->warn("  No se pudo descargar — saltando periodo {$period}");

                return;
            }

            $sizeMB = round(filesize($zipPath) / 1048576, 1);
            $this->line("  Descargado: {$sizeMB} MB");
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

    /**
     * Decide si el ZIP cacheado en disco sirve o hay que volver a descargarlo.
     *
     * La trampa que motivó esto: los ZIPs de un periodo abierto (el año o el mes en curso) se
     * regeneran a diario en origen, pero el comando reutilizaba a ciegas cualquier fichero
     * previo. Reprocesar en septiembre un volcado de marzo no revienta: reimporta contratos que
     * ya están en BD y reporta "0 nuevos", que se lee como "estamos al día" cuando en realidad
     * significa "no me he enterado de nada desde la última descarga". Medido el 2026-09-07: con
     * la caché de marzo, `agregacion 2026` daba 0 nuevos; tras borrar el ZIP y bajarlo otra vez,
     * 22.067 nuevos. Igual en `emp` (1.461) y `licitaciones` (28.406). Seis meses invisibles.
     *
     * Los periodos ya cerrados sí son inmutables, así que se reutilizan sin tocar la red: ese
     * directorio ronda los 16 GB y revalidarlo entero no aporta nada.
     */
    private function resolveCacheState(string $zipPath, string $period, string $url): string
    {
        if ((bool) $this->option('force-download')) {
            return self::CACHE_FORCED;
        }

        if (! file_exists($zipPath) || filesize($zipPath) < 1024) {
            return self::CACHE_MISS;
        }

        if (! $this->isOpenPeriod($period)) {
            return self::CACHE_HIT;
        }

        return $this->remoteHasChanged($url, $zipPath) ? self::CACHE_STALE : self::CACHE_HIT;
    }

    /**
     * Un periodo está "abierto" mientras su volcado remoto puede seguir creciendo: el año en
     * curso para los ZIPs anuales, el mes en curso para los mensuales.
     */
    private function isOpenPeriod(string $period): bool
    {
        $now = now();

        if (preg_match('/^\d{4}$/', $period)) {
            return $period === $now->format('Y');
        }

        if (preg_match('/^\d{6}$/', $period)) {
            return $period === $now->format('Ym');
        }

        // Formato inesperado: revalidar es lo seguro.
        return true;
    }

    /**
     * Revalida el ZIP local contra el remoto con un GET condicional en streaming.
     *
     * Por qué así, medido contra contrataciondelsectorpublico.gob.es el 2026-09-07:
     *  - HEAD no sirve: el servidor corta la conexión TLS (curl 56, código HTTP 000).
     *  - `Range: bytes=0-0` tampoco: ignora el rango, responde 200 y suelta el cuerpo entero
     *    (101 MB en 5,3 s), que es justo lo que se quería evitar.
     *  - No hay Content-Length que comparar: responde con Transfer-Encoding chunked.
     *  - Sí honra If-Modified-Since (devuelve 304) y sí expone Last-Modified y ETag.
     * Un GET con 'stream' => true entrega las cabeceras sin descargar el cuerpo (0,15 s), así
     * que la misma petición vale para leer el 304 y, si no lo hay, comparar Last-Modified.
     *
     * Como validador local se usa el mtime del fichero, que se escribió al terminar la descarga
     * y por tanto es >= al Last-Modified que el remoto tenía en ese momento.
     */
    private function remoteHasChanged(string $url, string $zipPath): bool
    {
        $localMtime = filemtime($zipPath);

        if ($localMtime === false) {
            return true;
        }

        try {
            $response = Http::withOptions(['stream' => true] + SafeUrl::guzzleOptions())
                ->timeout(30)
                ->withHeaders([
                    'User-Agent' => self::USER_AGENT,
                    'If-Modified-Since' => gmdate('D, d M Y H:i:s', $localMtime).' GMT',
                ])
                ->get($url);
        } catch (\Throwable $e) {
            $this->warn("  No se pudo revalidar la caché: {$e->getMessage()}");

            return $this->isCacheTooOld($localMtime);
        }

        if ($response->status() === 304) {
            return false;
        }

        if (! $response->successful()) {
            $this->warn("  Revalidación no concluyente (HTTP {$response->status()}).");

            return $this->isCacheTooOld($localMtime);
        }

        $remoteMtime = strtotime((string) $response->header('Last-Modified'));

        if ($remoteMtime === false) {
            $this->warn('  El remoto no expone Last-Modified.');

            return $this->isCacheTooOld($localMtime);
        }

        return $remoteMtime > $localMtime;
    }

    /**
     * Plan B cuando el remoto no deja revalidar. En un periodo abierto se prefiere redescargar
     * de más antes que volver a reportar "0 nuevos" sobre un volcado viejo.
     */
    private function isCacheTooOld(int $localMtime): bool
    {
        $ageHours = (time() - $localMtime) / 3600;

        $this->line(sprintf(
            '  Caché local de %.1f h (límite sin revalidar: %d h).',
            $ageHours,
            self::CACHE_MAX_AGE_HOURS
        ));

        return $ageHours > self::CACHE_MAX_AGE_HOURS;
    }

    private function downloadZip(string $url, string $path): bool
    {
        // Intento 1: curl (streaming directo a disco, sin cargar el ZIP en memoria).
        // Se salta bajo tests: al ser un proceso externo, Http::fake() no lo intercepta y la
        // suite acabaría golpeando la red real (y bajando >100 MB por periodo).
        if (! App::runningUnitTests()) {
            $escapedUrl = escapeshellarg($url);
            $escapedPath = escapeshellarg($path);
            $userAgent = escapeshellarg(self::USER_AGENT);
            // Redirecciones solo a https y como mucho 5: una redirección del servidor no
            // puede desviar la descarga a http, file:// ni a otros protocolos.
            $cmd = "curl -sL --proto =https --proto-redir =https --max-redirs 5 --max-time 600 -A {$userAgent} -o {$escapedPath} {$escapedUrl}";
            exec($cmd, $output, $exitCode);

            if ($exitCode === 0 && file_exists($path) && filesize($path) > 1024) {
                if (str_starts_with((string) file_get_contents($path, false, null, 0, 4), 'PK')) {
                    return true;
                }
            }
        }

        // Intento 2: HTTP client con sink (streaming a disco)
        @unlink($path);
        $this->line('  Reintentando con HTTP sink...');

        try {
            $response = Http::withOptions([
                'sink' => $path,
            ] + SafeUrl::guzzleOptions())
                ->timeout(600)
                ->withHeaders([
                    'User-Agent' => self::USER_AGENT,
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
