<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Enriquece contratos de Andalucía con URL y fecha desde JSON descargados
 * de la API ElasticSearch del portal PDC de la Junta de Andalucía.
 *
 * Flujo:
 *   1. Ejecutar: node scripts/scrape-anda-es.mjs  (descarga JSONs con headless browser)
 *   2. Ejecutar: php artisan regional:enrich-anda  (importa desde JSONs a BD)
 *
 * Los JSON se guardan en storage/app/anda-enrichment/anda-YYYY.json
 */
class EnrichAndaluciaUrls extends Command
{
    protected $signature = 'regional:enrich-anda
        {--dry-run : Mostrar sin aplicar}
        {--limit=0 : Límite de registros (0 = todos)}';

    protected $description = 'Enriquece contratos de Andalucía con URL y fecha desde la API ES del portal PDC';

    private const JSON_DIR = 'storage/app/anda-enrichment';

    private const DETAIL_URL_BASE = 'https://www.juntadeandalucia.es/haciendayadministracionpublica/apl/pdc-front-publico/perfiles-licitaciones/detalle-licitacion?idExpediente=';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');

        $this->info($dryRun ? '[DRY-RUN] Analizando...' : 'Enriqueciendo contratos Andalucía...');

        // Show current stats
        $this->showStats();

        // Load JSON data
        $jsonFile = base_path(self::JSON_DIR.'/anda-all.json');
        if (! file_exists($jsonFile)) {
            $this->error("No existe {$jsonFile}");
            $this->info('Ejecuta primero: node scripts/scrape-anda-es.mjs');

            return self::FAILURE;
        }

        $this->info("Cargando {$jsonFile}...");
        $records = json_decode((string) file_get_contents($jsonFile), true);
        if (! is_array($records)) {
            $this->error('Fichero JSON inválido');

            return self::FAILURE;
        }

        // Build lookup: expediente → {id, fecha}
        // Format: {id: 123, exp: "CONTR 2025 ...", fecha: "2025-..."}
        $lookup = [];
        foreach ($records as $record) {
            $expediente = trim($record['exp'] ?? '');
            if ($expediente === '') {
                continue;
            }
            $lookup[$expediente] = [
                'id' => $record['id'] ?? null,
                'fecha' => $this->parseDate($record['fecha'] ?? ''),
            ];
        }

        $this->info('Total registros API: '.number_format(count($records)));
        $this->info('Expedientes únicos en lookup: '.number_format(count($lookup)));

        // Query ANDA contracts without URL
        $query = DB::table('contratos')
            ->where('placsp_id', 'LIKE', 'ANDA-%')
            ->where(function ($q) {
                $q->whereNull('url_placsp')->orWhere('url_placsp', '');
            })
            ->whereNotNull('expediente')
            ->whereRaw("TRIM(expediente) != ''");

        if ($limit > 0) {
            $query->limit($limit);
        }

        $totalToProcess = (clone $query)->count();
        $this->info('Contratos ANDA sin URL a procesar: '.number_format($totalToProcess));
        $this->newLine();

        // Process in chunks with batch UPDATE for PostgreSQL
        $matched = 0;
        $urlUpdated = 0;
        $fechaUpdated = 0;
        $notFound = 0;
        $processed = 0;
        $chunkSize = 2000;

        $query->select(['id', 'expediente', 'fecha_publicacion'])
            ->chunkById($chunkSize, function ($contratos) use (
                $lookup, $dryRun, &$matched, &$urlUpdated, &$fechaUpdated, &$notFound, &$processed, $totalToProcess
            ) {
                $urlBatch = [];   // [id => url]
                $fechaBatch = []; // [id => fecha]

                foreach ($contratos as $contrato) {
                    $processed++;
                    $expediente = trim($contrato->expediente);

                    if (isset($lookup[$expediente])) {
                        $matched++;
                        $data = $lookup[$expediente];

                        if ($data['id']) {
                            $urlBatch[$contrato->id] = self::DETAIL_URL_BASE.$data['id'];
                            $urlUpdated++;
                        }

                        if ($data['fecha'] && (empty($contrato->fecha_publicacion) || $contrato->fecha_publicacion === '')) {
                            $fechaBatch[$contrato->id] = $data['fecha'];
                            $fechaUpdated++;
                        }
                    } else {
                        $notFound++;
                    }
                }

                if (! $dryRun && (! empty($urlBatch) || ! empty($fechaBatch))) {
                    $this->batchUpdate($urlBatch, $fechaBatch);
                }

                if ($processed % 20000 === 0 || $processed === $totalToProcess) {
                    $pct = $totalToProcess > 0 ? round($processed / $totalToProcess * 100, 1) : 0;
                    $this->info("  Procesados: {$processed}/{$totalToProcess} ({$pct}%) — Matched: {$matched}, No match: {$notFound}");
                }

                return true;
            });

        // Summary
        $this->newLine();
        $prefix = $dryRun ? '[DRY-RUN] ' : '';
        $this->table(['Métrica', 'Valor'], [
            ["{$prefix}Procesados", number_format($processed)],
            ["{$prefix}Matched (expediente encontrado)", number_format($matched)],
            ["{$prefix}URL actualizada", number_format($urlUpdated)],
            ["{$prefix}Fecha publicación actualizada", number_format($fechaUpdated)],
            ['Sin match en API', number_format($notFound)],
        ]);

        if ($dryRun) {
            $this->warn('Ejecuta sin --dry-run para aplicar cambios.');
        }

        return self::SUCCESS;
    }

    /**
     * Batch update url_placsp and fecha_publicacion using PostgreSQL VALUES clause.
     *
     * @param  array<int, string>  $urlBatch  [contrato_id => url]
     * @param  array<int, string>  $fechaBatch  [contrato_id => fecha]
     */
    private function batchUpdate(array $urlBatch, array $fechaBatch): void
    {
        // Update URLs in batches of 500 using VALUES
        if (! empty($urlBatch)) {
            $chunks = array_chunk($urlBatch, 500, true);
            foreach ($chunks as $chunk) {
                $values = [];
                $bindings = [];
                foreach ($chunk as $id => $url) {
                    $values[] = '(?, ?)';
                    $bindings[] = $id;
                    $bindings[] = $url;
                }
                $valuesSql = implode(', ', $values);
                DB::statement(
                    "UPDATE contratos SET url_placsp = v.url
                     FROM (VALUES {$valuesSql}) AS v(id, url)
                     WHERE contratos.id = v.id::bigint",
                    $bindings
                );
            }
        }

        // Update fechas
        if (! empty($fechaBatch)) {
            $chunks = array_chunk($fechaBatch, 500, true);
            foreach ($chunks as $chunk) {
                $values = [];
                $bindings = [];
                foreach ($chunk as $id => $fecha) {
                    $values[] = '(?, ?)';
                    $bindings[] = $id;
                    $bindings[] = $fecha;
                }
                $valuesSql = implode(', ', $values);
                DB::statement(
                    "UPDATE contratos SET fecha_publicacion = v.fecha::date
                     FROM (VALUES {$valuesSql}) AS v(id, fecha)
                     WHERE contratos.id = v.id::bigint
                     AND (contratos.fecha_publicacion IS NULL)",
                    $bindings
                );
            }
        }
    }

    private function showStats(): void
    {
        $stats = DB::selectOne("
            SELECT
                COUNT(*) as total,
                SUM(CASE WHEN url_placsp IS NOT NULL AND url_placsp != '' THEN 1 ELSE 0 END) as con_url,
                SUM(CASE WHEN url_placsp IS NULL OR url_placsp = '' THEN 1 ELSE 0 END) as sin_url,
                SUM(CASE WHEN TRIM(expediente) LIKE 'CONTR%' AND (url_placsp IS NULL OR url_placsp = '') THEN 1 ELSE 0 END) as contr_sin_url,
                SUM(CASE WHEN fecha_publicacion IS NULL THEN 1 ELSE 0 END) as sin_fecha
            FROM contratos
            WHERE placsp_id LIKE 'ANDA-%'
        ");

        $this->table(['Métrica', 'Valor'], [
            ['ANDA total', number_format((int) $stats->total)],
            ['Con URL', number_format((int) $stats->con_url)],
            ['Sin URL', number_format((int) $stats->sin_url)],
            ['CONTR* sin URL (buscable)', number_format((int) $stats->contr_sin_url)],
            ['Sin fecha publicación', number_format((int) $stats->sin_fecha)],
        ]);
    }

    private function parseDate(string $dateStr): ?string
    {
        if ($dateStr === '') {
            return null;
        }

        try {
            $dt = new \DateTimeImmutable($dateStr);

            return $dt->format('Y-m-d');
        } catch (\Exception) {
            return null;
        }
    }
}
