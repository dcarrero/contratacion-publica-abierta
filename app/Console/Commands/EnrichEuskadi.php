<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Contrato;
use App\Services\Regional\PaisVascoParser;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class EnrichEuskadi extends Command
{
    protected $signature = 'regional:enrich-eusk
        {--year=* : Años a enriquecer (ej: --year=2024). Por defecto todos desde 2018}
        {--concurrency=10 : Número de requests HTTP concurrentes}
        {--limit=0 : Límite de XMLs a procesar por año (0 = todos)}
        {--dry-run : Solo mostrar estadísticas, no actualizar}';

    protected $description = 'Enriquece contratos de Euskadi con datos del XML detallado (importes, adjudicatario, CPV)';

    private int $enriched = 0;

    private int $skipped = 0;

    private int $errors = 0;

    private int $noXmlUrl = 0;

    public function handle(): int
    {
        $years = $this->option('year');
        $concurrency = (int) $this->option('concurrency');
        $limit = (int) $this->option('limit');
        $dryRun = (bool) $this->option('dry-run');

        if (empty($years)) {
            $years = range(2018, (int) date('Y'));
        }
        $years = array_map('intval', $years);

        $this->info($dryRun ? 'Modo DRY-RUN' : 'Enriqueciendo contratos de Euskadi con XML detallado...');
        $this->info("Concurrencia: {$concurrency} requests simultáneos");

        $startTime = microtime(true);
        $parser = new PaisVascoParser;
        $baseUrl = 'https://opendata.euskadi.eus/contenidos/ds_contrataciones/contrataciones_admin_{YEAR}/opendata/contratos.json';

        foreach ($years as $year) {
            $url = str_replace('{YEAR}', (string) $year, $baseUrl);
            $this->newLine();
            $this->info("=== Año {$year} ===");

            // Descargar JSON
            $tempFile = storage_path("app/eusk_temp_enrich_{$year}.json");
            $this->line('Descargando JSON...');

            if (! $this->downloadFile($url, $tempFile)) {
                continue;
            }

            // Cargar y extraer URLs XML
            $records = $this->loadJsonFile($tempFile);
            @unlink($tempFile);

            if ($records === null) {
                $this->error("Error parseando JSON para año {$year}");

                continue;
            }

            $this->info('  '.count($records).' registros en JSON');

            // Construir mapa expediente→xmlUrl
            $xmlMap = [];
            $zipSkipped = 0;
            foreach ($records as $record) {
                $expediente = $record['contratacion_expediente'] ?? null;
                $xmlUrl = trim($record['dataXML'] ?? '');
                if (! empty($expediente) && ! empty($xmlUrl)) {
                    // Saltar URLs .zip (2018 y anteriores) — no son XML directo
                    if (str_ends_with($xmlUrl, '.zip')) {
                        $zipSkipped++;

                        continue;
                    }
                    $placspId = 'EUSK-'.$expediente;
                    $xmlMap[$placspId] = $xmlUrl;
                }
            }
            if ($zipSkipped > 0) {
                $this->info("  {$zipSkipped} registros con URL .zip (no soportadas, omitidos)");
            }
            unset($records);
            gc_collect_cycles();

            $this->info('  '.count($xmlMap).' registros con URL XML');

            // Filtrar: solo contratos que existen en BD y necesitan enriquecimiento
            $placspIds = array_keys($xmlMap);
            $needEnrichment = Contrato::whereIn('placsp_id', $placspIds)
                ->where(function ($q) {
                    $q->whereNull('importe_adjudicacion')
                        ->orWhere(function ($q2) {
                            $q2->whereNull('nif_adjudicatario')
                                ->orWhere('nif_adjudicatario', 'like', 'EUSK-%');
                        })
                        ->orWhereNull('tipo_contrato')
                        ->orWhereNull('procedimiento')
                        ->orWhereNull('cpv');
                })
                ->pluck('placsp_id')
                ->all();

            // Filtrar xmlMap solo a los que necesitan enriquecimiento
            $toProcess = [];
            foreach ($needEnrichment as $pid) {
                if (isset($xmlMap[$pid])) {
                    $toProcess[$pid] = $xmlMap[$pid];
                }
            }
            unset($xmlMap, $needEnrichment);

            $total = count($toProcess);
            $this->info("  {$total} contratos necesitan enriquecimiento");

            if ($total === 0) {
                continue;
            }

            if ($limit > 0 && $total > $limit) {
                $toProcess = array_slice($toProcess, 0, $limit, true);
                $total = count($toProcess);
                $this->info("  Limitado a {$total}");
            }

            if ($dryRun) {
                $this->skipped += $total;

                continue;
            }

            // Procesar en lotes concurrentes
            $bar = $this->output->createProgressBar($total);
            $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% | %elapsed:6s% | OK: %enriched% | Err: %errors%');
            $bar->setMessage('0', 'enriched');
            $bar->setMessage('0', 'errors');
            $bar->start();

            $batches = array_chunk($toProcess, $concurrency, true);

            foreach ($batches as $batch) {
                $this->processBatch($batch, $parser);

                $bar->setMessage((string) $this->enriched, 'enriched');
                $bar->setMessage((string) $this->errors, 'errors');
                $bar->advance(count($batch));
            }

            $bar->finish();
            $this->newLine();
        }

        $duration = (int) (microtime(true) - $startTime);
        $this->showSummary($duration, $dryRun);

        return self::SUCCESS;
    }

    private function processBatch(array $batch, PaisVascoParser $parser): void
    {
        // Lanzar requests concurrentes
        $responses = Http::pool(function ($pool) use ($batch) {
            foreach ($batch as $placspId => $xmlUrl) {
                $pool->as($placspId)
                    ->timeout(15)
                    ->retry(2, 500)
                    ->get($xmlUrl);
            }
        });

        // Procesar respuestas
        foreach ($batch as $placspId => $xmlUrl) {
            try {
                $response = $responses[$placspId] ?? null;

                // Http::pool() devuelve RequestException cuando falla, no Response
                if (! ($response instanceof Response) || ! $response->successful()) {
                    $this->errors++;

                    continue;
                }

                $xmlData = $parser->parseXmlDetail($response->body());

                if (empty($xmlData)) {
                    $this->skipped++;

                    continue;
                }

                // Actualizar contrato directamente
                $updateData = [];

                if (isset($xmlData['importe_adjudicacion'])) {
                    $updateData['importe_adjudicacion'] = $xmlData['importe_adjudicacion'];
                }
                if (isset($xmlData['importe_adjudicacion_con_iva'])) {
                    $updateData['importe_adjudicacion_con_iva'] = $xmlData['importe_adjudicacion_con_iva'];
                }
                if (isset($xmlData['importe_licitacion'])) {
                    $updateData['importe_licitacion'] = $xmlData['importe_licitacion'];
                }
                if (isset($xmlData['importe_licitacion_con_iva'])) {
                    $updateData['importe_licitacion_con_iva'] = $xmlData['importe_licitacion_con_iva'];
                }
                if (isset($xmlData['nif_adjudicatario'])) {
                    $updateData['nif_adjudicatario'] = mb_strtoupper($xmlData['nif_adjudicatario']);
                }
                if (isset($xmlData['nombre_adjudicatario'])) {
                    $updateData['nombre_adjudicatario'] = $xmlData['nombre_adjudicatario'];
                }
                if (isset($xmlData['cpv'])) {
                    $updateData['cpv'] = $xmlData['cpv'];
                }
                if (isset($xmlData['procedimiento'])) {
                    $updateData['procedimiento'] = $xmlData['procedimiento'];
                }
                if (isset($xmlData['tipo_contrato']) && ! empty($xmlData['tipo_contrato'])) {
                    $updateData['tipo_contrato'] = $xmlData['tipo_contrato'];
                }
                if (isset($xmlData['num_ofertas'])) {
                    $updateData['num_ofertas'] = $xmlData['num_ofertas'];
                }
                if (isset($xmlData['duracion'])) {
                    $updateData['duracion'] = $xmlData['duracion'];
                }
                if (isset($xmlData['fecha_adjudicacion'])) {
                    $updateData['fecha_adjudicacion'] = $xmlData['fecha_adjudicacion'];
                }

                if (! empty($updateData)) {
                    Contrato::where('placsp_id', $placspId)->update($updateData);
                    $this->enriched++;
                } else {
                    $this->skipped++;
                }

            } catch (\Throwable $e) {
                $this->errors++;

                if ($this->errors <= 10) {
                    $this->warn("Error {$placspId}: {$e->getMessage()}");
                }
            }
        }
    }

    private function downloadFile(string $url, string $path): bool
    {
        try {
            $ch = curl_init($url);
            $fp = fopen($path, 'w');
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
                @unlink($path);

                return false;
            }

            $fileSize = filesize($path);
            $this->info('  Descargado: '.round($fileSize / 1048576, 1).' MB');

            return true;
        } catch (\Throwable $e) {
            $this->error("Error descargando: {$e->getMessage()}");
            @unlink($path);

            return false;
        }
    }

    /**
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

            // Strip JSONP wrapper if present (years 2018-2020)
            if (str_starts_with($content, 'jsonCallback(')) {
                $content = substr($content, 13);
                $content = rtrim($content);
                $content = rtrim($content, ';');
                $content = rtrim($content, ')');
            }

            $data = json_decode($content, true);
            unset($content);

            return is_array($data) ? $data : null;
        } finally {
            ini_set('memory_limit', $previousLimit ?: '1G');
        }
    }

    private function showSummary(int $duration, bool $dryRun): void
    {
        $prefix = $dryRun ? '[DRY-RUN] ' : '';
        $this->newLine();
        $this->info("{$prefix}Resumen enriquecimiento Euskadi:");
        $this->table(
            ['Métrica', 'Valor'],
            [
                ['Enriquecidos', number_format($this->enriched)],
                ['Sin datos en XML', number_format($this->skipped)],
                ['Sin URL XML', number_format($this->noXmlUrl)],
                ['Errores HTTP', number_format($this->errors)],
                ['Duración', "{$duration}s (".round($duration / 60, 1).' min)'],
            ]
        );
    }
}
