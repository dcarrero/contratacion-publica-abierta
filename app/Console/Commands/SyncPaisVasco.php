<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\FuenteDatos;
use App\Models\ImportLog;
use App\Services\ContratoImporter;
use App\Services\Regional\PaisVascoParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class SyncPaisVasco extends Command
{
    protected $signature = 'regional:sync-eusk
        {--year=* : Años a importar (ej: --year=2024 --year=2025). Por defecto todos desde 2018}
        {--dry-run : Parsear y mostrar sin insertar datos}
        {--limit=0 : Limite de registros a procesar por año (0 = todos)}
        {--enrich : Descargar XML detallado para obtener importes y adjudicatario (lento)}
        {--enrich-limit=0 : Limite de XMLs a descargar por año (0 = todos)}';

    protected $description = 'Importa contratos desde el catálogo JSON anual del Gobierno Vasco (opendata.euskadi.eus)';

    private int $processed = 0;

    private int $created = 0;

    private int $updated = 0;

    private int $skipped = 0;

    private int $errors = 0;

    private int $noNif = 0;

    private int $enriched = 0;

    public function handle(ContratoImporter $importer): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');
        $enrich = (bool) $this->option('enrich');
        $enrichLimit = (int) $this->option('enrich-limit');
        $years = $this->option('year');

        if (empty($years)) {
            $years = range(2018, (int) date('Y'));
        }
        $years = array_map('intval', $years);

        $this->info($dryRun ? 'Modo DRY-RUN: no se insertaran datos.' : 'Importando contratos del Pais Vasco...');
        if ($enrich) {
            $this->info('Modo ENRICH: se descargara XML detallado por contrato (lento).');
        }

        $startTime = microtime(true);

        $fuenteDatosId = null;
        if (! $dryRun) {
            $fuente = FuenteDatos::where('slug', 'eusk-contratos')->first();
            if (! $fuente) {
                $this->error('Fuente de datos "eusk-contratos" no encontrada. Ejecuta: php artisan db:seed');

                return self::FAILURE;
            }
            $fuenteDatosId = $fuente->id;
        }

        $parser = new PaisVascoParser;
        $baseUrl = 'https://opendata.euskadi.eus/contenidos/ds_contrataciones/contrataciones_admin_{YEAR}/opendata/contratos.json';

        foreach ($years as $year) {
            $url = str_replace('{YEAR}', (string) $year, $baseUrl);
            $this->newLine();
            $this->info("=== Año {$year} ===");

            $yearProcessed = 0;

            // Descargar a fichero para evitar memory exhaustion en JSONs grandes (>200MB)
            $tempFile = storage_path("app/eusk_temp_{$year}.json");
            $this->line("Descargando a {$tempFile}...");

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
                    $this->error("Error descargando año {$year}: HTTP {$httpCode} {$curlError}");
                    @unlink($tempFile);
                    $this->errors++;

                    continue;
                }
            } catch (\Throwable $e) {
                $this->error("Error descargando año {$year}: {$e->getMessage()}");
                @unlink($tempFile);
                $this->errors++;

                continue;
            }

            $fileSize = filesize($tempFile);
            $this->info('  Descargado: '.round($fileSize / 1048576, 1).' MB');

            // Parsear JSON en chunks para limitar memoria
            $records = $this->loadJsonFile($tempFile);

            if ($records === null) {
                $this->error("Error parseando JSON para año {$year}");
                @unlink($tempFile);
                $this->errors++;

                continue;
            }

            $totalRecords = count($records);
            $this->info("  {$totalRecords} registros");

            $bar = $this->output->createProgressBar($totalRecords);
            $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% | %elapsed:6s% | Nuevos: %created% | Err: %errors%');
            $bar->setMessage('0', 'created');
            $bar->setMessage('0', 'errors');
            $bar->start();

            foreach ($records as $record) {
                if ($limit > 0 && $yearProcessed >= $limit) {
                    break;
                }

                $this->processed++;
                $yearProcessed++;

                try {
                    $data = $parser->parse($record);

                    if ($data === null) {
                        $this->skipped++;
                        $bar->advance();

                        continue;
                    }

                    // Enriquecer con XML si se pide
                    if ($enrich && ! empty($data['data_xml_url'])) {
                        if ($enrichLimit === 0 || $this->enriched < $enrichLimit) {
                            $xmlData = $this->fetchXmlDetail($parser, $data['data_xml_url']);
                            if (! empty($xmlData)) {
                                $data = array_merge($data, $xmlData);
                                $this->enriched++;
                            }
                        }
                    }

                    // No guardar data_xml_url en la BD
                    unset($data['data_xml_url']);

                    if (empty($data['nif_adjudicatario'])) {
                        $this->noNif++;
                    }

                    $data['fuente_datos_id'] = $fuenteDatosId;
                    $data['tipo_registro'] = $data['es_menor'] ? 'contrato_menor' : 'licitacion';

                    if ($dryRun) {
                        $this->created++;
                        $bar->setMessage((string) $this->created, 'created');
                        $bar->advance();

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
                        $this->warn("Error: {$e->getMessage()}");
                    }
                }

                if ($this->processed % 500 === 0) {
                    $bar->setMessage((string) $this->created, 'created');
                    $bar->setMessage((string) $this->errors, 'errors');
                }

                $bar->advance();
            }

            $bar->setMessage((string) $this->created, 'created');
            $bar->setMessage((string) $this->errors, 'errors');
            $bar->finish();
            $this->newLine();

            // Liberar memoria y borrar fichero temporal
            unset($records);
            @unlink($tempFile);
            gc_collect_cycles();
        }

        $duration = (int) (microtime(true) - $startTime);

        if (! $dryRun && $fuenteDatosId) {
            ImportLog::create([
                'fuente_datos_id' => $fuenteDatosId,
                'tipo' => 'sync-eusk',
                'procesados' => $this->processed,
                'nuevos' => $this->created,
                'actualizados' => $this->updated,
                'ignorados' => $this->skipped,
                'errores' => $this->errors,
                'duracion_segundos' => $duration,
                'notas' => 'JSON anual Gobierno Vasco: años '.implode(', ', $years),
            ]);

            FuenteDatos::where('slug', 'eusk-contratos')
                ->update(['ultima_sincronizacion' => now()]);
        }

        $this->showSummary($duration, $dryRun);

        return self::SUCCESS;
    }

    /**
     * Carga un fichero JSON grande incrementando memoria temporalmente.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function loadJsonFile(string $path): ?array
    {
        $previousLimit = ini_get('memory_limit');
        ini_set('memory_limit', '2G');

        try {
            $content = file_get_contents($path);
            if ($content === false) {
                return null;
            }

            // Strip JSONP wrapper if present (years 2018-2020 use "jsonCallback([...]);")
            if (str_starts_with($content, 'jsonCallback(')) {
                $content = substr($content, 13); // Remove "jsonCallback("
                $content = rtrim($content);
                $content = rtrim($content, ';');  // Remove trailing ";"
                $content = rtrim($content, ')');  // Remove trailing ")"
            }

            $data = json_decode($content, true);
            unset($content);

            return is_array($data) ? $data : null;
        } finally {
            ini_set('memory_limit', $previousLimit ?: '1G');
        }
    }

    private function fetchXmlDetail(PaisVascoParser $parser, string $url): array
    {
        try {
            $response = Http::timeout(15)
                ->retry(2, 1000)
                ->get($url);

            if ($response->successful()) {
                return $parser->parseXmlDetail($response->body());
            }
        } catch (\Throwable) {
            // Silently skip XML enrichment errors
        }

        return [];
    }

    private function showSummary(int $duration, bool $dryRun): void
    {
        $prefix = $dryRun ? '[DRY-RUN] ' : '';

        $this->newLine();
        $this->info("{$prefix}Resumen importacion Pais Vasco:");
        $this->table(
            ['Metrica', 'Valor'],
            [
                ['Procesados', number_format($this->processed)],
                ['Nuevos', number_format($this->created)],
                ['Actualizados', number_format($this->updated)],
                ['Sin cambios', number_format($this->skipped)],
                ['Sin NIF adj.', number_format($this->noNif)],
                ['Enriquecidos (XML)', number_format($this->enriched)],
                ['Errores', number_format($this->errors)],
                ['Duracion', "{$duration}s"],
            ]
        );
    }
}
