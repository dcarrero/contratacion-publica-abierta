<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Support\RegionalSyncCommand;
use App\Models\FuenteDatos;
use App\Services\ContratoImporter;
use App\Services\Regional\ExistingContractIndex;
use App\Services\Regional\MadridAytoParser;
use App\Support\SafeUrl;
use Illuminate\Support\Facades\DB;
use League\Csv\Reader;

/**
 * Importa los CONTRATOS MENORES del Ayuntamiento de Madrid desde su portal de
 * datos abiertos (CKAN 2.9.11, https://datos.madrid.es, licencia CC BY 4.0).
 *
 * Es el único hueco de cobertura real detectado en el proyecto: las licitaciones
 * del Ayuntamiento sí llegan por PLACSP (sindicación 643), pero sus ~71.500
 * contratos menores no están en ninguna sindicación (de ellos PLACSP sólo expone
 * ~3.400). El dataset hermano `216876-0-contratos-actividad` (NO menores) se
 * ignora a propósito: es redundante con PLACSP y viene sin CPV, NUTS ni URL.
 *
 * Particularidades del portal, medidas empíricamente:
 *
 *  1. Las URLs de recurso NO se pueden construir: el índice del recurso no
 *     correlaciona con el año (2024 → 300253-2, 2023 → 300253-4, 2026 → 300253-26).
 *     Hay que resolverlas con package_show y leer el año de la descripción del
 *     recurso. No hay extensión datastore (datastore_search devuelve 404), así
 *     que se descargan los CSV completos.
 *  2. Los encodings son mixtos por año y el Content-Type miente (siempre dice
 *     charset=utf-8): medido, cp1252 en 2015-2019 y 2021-2026, y UTF-8 con BOM
 *     en 2020 y 2022. Se detecta por fichero (ver detectEncoding()), incluyendo
 *     los OEM de DOS (CP850/CP437) que aparecen en otros datasets del portal.
 *  3. El esquema cambia cinco veces entre 2015 y 2026 (de 9 a 19 columnas). Se
 *     resuelve por alias de cabecera en MadridAytoParser, no por franja de años.
 *  4. Separador ';', coma decimal, campos multilínea entrecomillados, algún
 *     fichero (2023) con líneas de título antes de la cabecera y 2021 partido en
 *     dos ficheros (hasta febrero / desde marzo).
 *  5. robots.txt declara Crawl-delay: 10 → se respeta entre descargas.
 */
class SyncMadridAyto extends RegionalSyncCommand
{
    protected $signature = 'regional:sync-mad-ayto
        {file? : Ruta a un CSV local (si no se indica, descarga automáticamente)}
        {--year=* : Años a importar (repetible, ej: --year=2026 --year=2025). Por defecto: año actual}
        {--full : Importa todos los años disponibles en el dataset}
        {--chunk=500 : Registros por chunk}
        {--dry-run : Parsear y mostrar sin insertar datos}
        {--limit=0 : Limite de registros a procesar (0 = todos)}';

    protected $description = 'Importa los contratos menores del Ayuntamiento de Madrid (datos.madrid.es)';

    /**
     * API CKAN del portal municipal. package_show es la única vía fiable para
     * resolver la URL de descarga de cada año.
     */
    private const CKAN_PACKAGE_SHOW = 'https://datos.madrid.es/api/3/action/package_show?id=';

    private const DATASET_ID = '300253-0-contratos-actividad-menores';

    private const DATASET_URL = 'https://datos.madrid.es/dataset/300253-0-contratos-actividad-menores';

    /**
     * robots.txt del portal: Crawl-delay: 10.
     */
    private const CRAWL_DELAY_SECONDS = 10;

    private const USER_AGENT = 'ContratacionAbierta/2.0 (transparencia)';

    /**
     * Claves naturales ya vistas en esta ejecución, para desambiguar los
     * duplicados que trae el origen (~2% en 2015-2017, ~0,8% en 2018).
     *
     * @var array<string, true>
     */
    private array $seenKeys = [];

    private bool $hasDownloaded = false;

    /**
     * Contratos del Ayuntamiento que ya tenemos por otras fuentes (PLACSP),
     * indexados por importe para descartar los que este CSV repetiría.
     */
    private ?ExistingContractIndex $existing = null;

    /**
     * placsp_id que ya son de esta fuente, como conjunto (clave => posición).
     *
     * @var array<string, int>
     */
    private array $ownPlacspIds = [];

    public function handle(ContratoImporter $importer): int
    {
        // Artisan reutiliza la instancia del comando dentro de un mismo proceso
        // (p. ej. dos llamadas seguidas en un test), así que el estado se reinicia
        // aquí: si no, la segunda ejecución vería todas las claves como duplicadas.
        $this->resetState();

        $dryRun = (bool) $this->option('dry-run');
        $file = $this->argument('file');

        $this->info($dryRun
            ? 'Modo DRY-RUN: no se insertaran datos.'
            : 'Importando contratos menores del Ayuntamiento de Madrid...');

        $startTime = microtime(true);

        $fuenteDatosId = $this->resolveFuenteDatos($dryRun);
        if ($fuenteDatosId === false) {
            return self::FAILURE;
        }

        $this->loadExistingIndex();

        $parser = new MadridAytoParser(self::DATASET_URL);

        if (is_string($file) && $file !== '') {
            if (! is_file($file)) {
                $this->error("Fichero no encontrado: {$file}");

                return self::FAILURE;
            }

            $this->info("Fichero: {$file}");

            $normalized = $this->toUtf8($file, 'local');
            if ($normalized === null) {
                return self::FAILURE;
            }

            $this->importCsvFile($normalized, $parser, $importer, $fuenteDatosId, $dryRun);
            $this->cleanupTemp($normalized, $file);

            $duration = (int) (microtime(true) - $startTime);
            $this->logImport($fuenteDatosId, $duration, $dryRun, ['fichero local']);
            $this->showSummary($duration, $dryRun);

            return self::SUCCESS;
        }

        $resources = $this->fetchResources();
        if ($resources === null) {
            $this->error('No se pudo consultar la API CKAN de datos.madrid.es');

            return self::FAILURE;
        }

        $years = $this->resolveYears($resources);
        if ($years === []) {
            $this->warn('No hay años que importar con los filtros indicados.');

            return self::SUCCESS;
        }

        $processed = [];

        foreach ($years as $year) {
            $this->newLine();
            $this->info("=== Año {$year} ===");

            foreach ($resources[$year] as $resource) {
                $temp = $this->downloadCsv($resource['url'], $year);
                if ($temp === null) {
                    continue;
                }

                $this->line('  Recurso: '.$resource['description']);
                $this->importCsvFile($temp, $parser, $importer, $fuenteDatosId, $dryRun);
                @unlink($temp);
            }

            $processed[] = (string) $year;
        }

        $duration = (int) (microtime(true) - $startTime);
        $this->logImport($fuenteDatosId, $duration, $dryRun, $processed);
        $this->showSummary($duration, $dryRun);

        return self::SUCCESS;
    }

    /**
     * Resuelve los recursos CSV del dataset agrupados por año.
     *
     * El año NO se puede deducir de la URL ni del índice del recurso: se lee de
     * la descripción ("Contratos menores 2021 (hasta febrero)").
     *
     * @return array<int, array<int, array{url: string, description: string}>>|null
     */
    private function fetchResources(): ?array
    {
        $json = $this->httpGet(self::CKAN_PACKAGE_SHOW.self::DATASET_ID);
        if ($json === null) {
            return null;
        }

        $data = json_decode($json, true);
        if (! is_array($data) || ! isset($data['result']['resources']) || ! is_array($data['result']['resources'])) {
            return null;
        }

        $byYear = [];

        foreach ($data['result']['resources'] as $resource) {
            if (mb_strtoupper((string) ($resource['format'] ?? '')) !== 'CSV') {
                continue;
            }

            $url = (string) ($resource['url'] ?? '');
            $description = trim((string) ($resource['description'] ?? $resource['name'] ?? ''));

            if ($url === '' || ! preg_match('/\b(20\d{2})\b/', $description, $m)) {
                continue;
            }

            // La URL la dicta el CKAN remoto: solo https y de datos.madrid.es (SSRF).
            if (! SafeUrl::isAllowedDownload($url, ['datos.madrid.es'])) {
                $this->warn("  Recurso fuera de datos.madrid.es, se ignora: {$url}");

                continue;
            }

            $byYear[(int) $m[1]][] = ['url' => $url, 'description' => $description];
        }

        ksort($byYear);

        return $byYear;
    }

    /**
     * @param  array<int, array<int, array{url: string, description: string}>>  $resources
     * @return array<int, int>
     */
    private function resolveYears(array $resources): array
    {
        $available = array_keys($resources);

        if ((bool) $this->option('full')) {
            return $available;
        }

        /** @var array<int, string> $requested */
        $requested = (array) $this->option('year');

        if ($requested === []) {
            $requested = [date('Y')];
        }

        $years = [];
        foreach ($requested as $year) {
            $year = (int) $year;
            if (! in_array($year, $available, true)) {
                $this->warn("El dataset no publica CSV para el año {$year} — se salta.");

                continue;
            }
            $years[] = $year;
        }

        return array_values(array_unique($years));
    }

    /**
     * Descarga un CSV respetando el Crawl-delay del portal y lo normaliza a UTF-8.
     */
    private function downloadCsv(string $url, int $year): ?string
    {
        // robots.txt: Crawl-delay 10. Sólo se espera entre descargas, no antes de la primera.
        if ($this->hasDownloaded) {
            sleep(self::CRAWL_DELAY_SECONDS);
        }
        $this->hasDownloaded = true;

        $raw = storage_path("app/mad_ayto_{$year}_".substr(md5($url), 0, 6).'.csv');
        $this->line("  Descargando: {$url}");

        $body = $this->httpGet($url);
        if ($body === null) {
            $this->warn('  Descarga fallida — se salta este recurso.');

            return null;
        }

        if (file_put_contents($raw, $body) === false) {
            $this->warn("  No se pudo escribir {$raw} — se salta.");

            return null;
        }

        $this->info('  Descargado: '.round(strlen($body) / 1024).' KB');

        $normalized = $this->toUtf8($raw, (string) $year);
        @unlink($raw);

        return $normalized;
    }

    private function httpGet(string $url): ?string
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }

        curl_setopt_array($ch, SafeUrl::curlOptions() + [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_FAILONERROR => true,
            CURLOPT_USERAGENT => self::USER_AGENT,
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if (! is_string($body) || $status !== 200) {
            $this->warn("  HTTP {$status} {$error}");

            return null;
        }

        return $body;
    }

    /**
     * Escribe una copia del CSV normalizada a UTF-8 sin BOM.
     *
     * Devuelve la ruta del fichero normalizado (temporal) o null si falla.
     */
    private function toUtf8(string $path, string $label): ?string
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            $this->error("  No se pudo leer {$path}");

            return null;
        }

        $encoding = $this->detectEncoding($raw);
        $this->line("  Encoding detectado: {$encoding}");

        if ($encoding === 'UTF-8') {
            // Quitar BOM: league/csv no lo descarta y contaminaría la primera cabecera.
            $utf8 = str_starts_with($raw, "\xEF\xBB\xBF") ? substr($raw, 3) : $raw;
        } else {
            $utf8 = $this->convertEncoding($raw, $encoding);
            if ($utf8 === null) {
                $this->error("  Conversión de {$encoding} a UTF-8 fallida");

                return null;
            }
        }

        $target = storage_path('app/mad_ayto_'.preg_replace('/[^A-Za-z0-9_]/', '', $label).'_utf8.csv');
        if (file_put_contents($target, $utf8) === false) {
            $this->error("  No se pudo escribir {$target}");

            return null;
        }

        return $target;
    }

    /**
     * Detecta el encoding real del fichero. El Content-Type del portal no sirve:
     * anuncia charset=utf-8 también en los ficheros cp1252.
     *
     * Estrategia: BOM o UTF-8 válido → UTF-8. Si no, se prueban los encodings de
     * un byte candidatos y gana el que produce más letras españolas plausibles.
     * Eso separa cp1252 de los OEM de DOS (CP850/CP437), donde una cabecera real
     * como "Año" se lee "A¤o" en cp1252 y "Año" sólo en CP850.
     */
    private function detectEncoding(string $raw): string
    {
        if (str_starts_with($raw, "\xEF\xBB\xBF") || mb_check_encoding($raw, 'UTF-8')) {
            return 'UTF-8';
        }

        $best = 'Windows-1252';
        $bestScore = -1;

        foreach (['Windows-1252', 'CP850', 'CP437'] as $candidate) {
            $converted = $this->convertEncoding($raw, $candidate);
            if ($converted === null) {
                continue;
            }

            $score = $this->spanishScore($converted);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $candidate;
            }
        }

        return $best;
    }

    private function convertEncoding(string $raw, string $encoding): ?string
    {
        if ($encoding === 'Windows-1252') {
            return mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        }

        // mbstring no conoce los OEM de DOS; iconv sí.
        $converted = @iconv($encoding, 'UTF-8//TRANSLIT', $raw);

        return is_string($converted) ? $converted : null;
    }

    /**
     * Cuenta caracteres típicos del castellano. Cuanto más alto, más plausible
     * es la interpretación del encoding.
     */
    private function spanishScore(string $text): int
    {
        return (int) preg_match_all('/[áéíóúñüÁÉÍÓÚÑÜ¿¡ºª€]/u', $text);
    }

    private function importCsvFile(
        string $file,
        MadridAytoParser $parser,
        ContratoImporter $importer,
        ?int $fuenteDatosId,
        bool $dryRun
    ): void {
        $headerOffset = $this->findHeaderOffset($file);
        if ($headerOffset === null) {
            $this->warn('  No se encontró una cabecera reconocible — se salta el fichero.');

            return;
        }

        if ($headerOffset > 0) {
            $this->line('  Cabecera en la línea '.($headerOffset + 1).' (líneas de título por delante).');
        }

        try {
            $reader = Reader::createFromPath($file, 'r');
            $reader->setDelimiter(';');
            $reader->setHeaderOffset($headerOffset);
            $header = $reader->getHeader();
        } catch (\Throwable $e) {
            $this->error("  Error abriendo CSV: {$e->getMessage()}");

            return;
        }

        $this->line('  Columnas ('.count($header).'): '.implode(', ', array_slice($header, 0, 6)).'...');

        $chunkSize = max(1, (int) $this->option('chunk'));
        $limit = (int) $this->option('limit');

        $batch = [];
        $preamble = 0;
        $desde = $this->processed;

        foreach ($reader->getRecords() as $record) {
            // league/csv sólo excluye la fila de cabecera: las líneas de título que
            // van por delante (2023) siguen llegando como registros. Se descartan
            // sin contarlas como filas procesadas.
            if ($preamble < $headerOffset) {
                $preamble++;

                continue;
            }

            if ($limit > 0 && $this->processed >= $limit) {
                break;
            }

            $this->processed++;

            try {
                /** @var array<string, string|null> $record */
                $data = $parser->parse($record);
            } catch (\Throwable $e) {
                $this->errors++;
                if ($this->errors <= 10) {
                    $this->warn("  Error fila {$this->processed}: {$e->getMessage()}");
                }

                continue;
            }

            if ($data === null) {
                $this->skipped++;

                continue;
            }

            $clave = (string) $data['clave_natural'];
            $repetidoEnFichero = isset($this->seenKeys[$clave]);
            $this->seenKeys[$clave] = true;

            $placspId = $parser->makePlacspId($data, $repetidoEnFichero);

            // Deduplicación ENTRE fuentes: el mismo contrato publicado por el
            // Ayuntamiento y por PLACSP lleva identificadores distintos, así que
            // el placsp_id nunca colisiona y hay que compararlo por contenido.
            // No se aplica a los que ya importamos nosotros (ver loadExistingIndex).
            if (! isset($this->ownPlacspIds[$placspId])
                && $this->existing !== null
                && $this->existing->isDuplicate($data)) {
                $this->duplicates++;

                continue;
            }

            $data['placsp_id'] = $placspId;
            unset($data['clave_natural']);

            if (empty($data['nif_adjudicatario'])) {
                $this->noNif++;
            }

            $data['fuente_datos_id'] = $fuenteDatosId;
            $data['tipo_registro'] = 'contrato_menor';

            if ($dryRun) {
                $this->created++;

                continue;
            }

            $batch[] = $data;

            if (count($batch) >= $chunkSize) {
                $this->flushBatch($batch, $importer);
                $batch = [];
            }
        }

        if ($batch !== []) {
            $this->flushBatch($batch, $importer);
        }

        $this->line('  Filas de este fichero: '.number_format($this->processed - $desde));
    }

    /**
     * Localiza la línea de cabecera: el fichero de 2023 lleva un título y una
     * línea vacía por delante ("CONTRATOS MENORES A 31 DE DICIEMBRE DE 2023;;;...").
     */
    private function findHeaderOffset(string $file): ?int
    {
        $handle = fopen($file, 'r');
        if ($handle === false) {
            return null;
        }

        $offset = null;

        for ($i = 0; $i < 20; $i++) {
            $line = fgets($handle);
            if ($line === false) {
                break;
            }

            $cells = array_filter(
                array_map('trim', explode(';', $line)),
                static fn (string $cell): bool => $cell !== ''
            );

            if (count($cells) >= 5) {
                $offset = $i;

                break;
            }
        }

        fclose($handle);

        return $offset;
    }

    /**
     * @param  array<int, array<string, mixed>>  $batch
     */
    private function flushBatch(array $batch, ContratoImporter $importer): void
    {
        try {
            $counts = $importer->importBatch($batch);
            $this->created += $counts['created'];
            $this->updated += $counts['updated'];
            $this->skipped += $counts['skipped'];
            $this->errors += $counts['errors'];
        } catch (\Throwable $e) {
            // Degradar a fila a fila para no perder el chunk entero.
            $this->warn("  Lote degradado a fila a fila: {$e->getMessage()}");

            foreach ($batch as $row) {
                try {
                    match ($importer->import($row)) {
                        'created' => $this->created++,
                        'updated' => $this->updated++,
                        'skipped' => $this->skipped++,
                    };
                } catch (\Throwable $rowError) {
                    $this->errors++;
                    if ($this->errors <= 10) {
                        $this->warn("  Fila (fallback): {$rowError->getMessage()}");
                    }
                }
            }
        }
    }

    /**
     * Precarga los contratos del Ayuntamiento que ya están en la base por otras
     * fuentes. Son ~26.500 filas de una tabla de 8,8M: cabe de sobra en memoria
     * y evita una consulta por cada una de las ~71.500 filas del CSV.
     *
     * Se excluyen los de esta misma fuente para que una reimportación no se vea
     * a sí misma como duplicada.
     */
    private function loadExistingIndex(): void
    {
        $fuenteId = FuenteDatos::where('slug', $this->fuenteSlug())->value('id');
        $fuenteId = $fuenteId !== null ? (int) $fuenteId : null;

        $this->existing = ExistingContractIndex::forOrgano(
            MadridAytoParser::NIF_AYUNTAMIENTO,
            $fuenteId
        );

        // Ids que ya son nuestros. Si un contrato que importamos en su día
        // aparece más tarde también en PLACSP, seguimos actualizando el nuestro
        // en vez de dejarlo congelado: descartarlo no borraría el duplicado ya
        // existente, sólo dejaría nuestra copia sin refrescar.
        $this->ownPlacspIds = $fuenteId === null
            ? []
            : DB::table('contratos')
                ->where('fuente_datos_id', $fuenteId)
                ->pluck('placsp_id')
                ->flip()
                ->all();

        $this->line(
            'Contratos del Ayuntamiento ya en la base por otras fuentes: '
            .number_format($this->existing->size())
            .' | ya importados por esta fuente: '.number_format(count($this->ownPlacspIds))
        );
    }

    private function resetState(): void
    {
        $this->seenKeys = [];
        $this->hasDownloaded = false;
        $this->existing = null;
        $this->ownPlacspIds = [];
        $this->duplicates = 0;
        $this->processed = 0;
        $this->created = 0;
        $this->updated = 0;
        $this->skipped = 0;
        $this->errors = 0;
        $this->noNif = 0;
    }

    /**
     * @return int|null|false int = id de la fuente, null = dry-run, false = error
     */
    private function resolveFuenteDatos(bool $dryRun): int|null|false
    {
        if ($dryRun) {
            return null;
        }

        $fuente = FuenteDatos::where('slug', $this->fuenteSlug())->first();
        if (! $fuente) {
            $this->error('Fuente de datos "'.$this->fuenteSlug().'" no encontrada. Ejecuta: php artisan db:seed');

            return false;
        }

        return $fuente->id;
    }

    private function cleanupTemp(string $normalized, string $original): void
    {
        if ($normalized !== $original) {
            @unlink($normalized);
        }
    }

    protected function tracksDuplicates(): bool
    {
        return true;
    }

    protected function importTipo(): string
    {
        return 'sync-mad-ayto';
    }

    protected function importRegionLabel(): string
    {
        return 'Ayuntamiento de Madrid (menores)';
    }

    protected function fuenteSlug(): string
    {
        return 'mad-ayto-menores';
    }
}
