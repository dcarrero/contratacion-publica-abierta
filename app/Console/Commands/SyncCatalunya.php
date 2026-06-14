<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\FuenteDatos;
use App\Models\ImportLog;
use App\Services\ContratoImporter;
use App\Services\Regional\CatalunyaParser;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class SyncCatalunya extends Command
{
    protected $signature = 'regional:sync-cat
        {--dry-run : Parsear y mostrar sin insertar datos}
        {--full : Descargar TODO el dataset (sin filtro de fecha)}
        {--since= : Fecha desde la que sincronizar (ISO: 2026-01-01)}
        {--limit=0 : Limite de registros a procesar (0 = todos)}
        {--offset=0 : Offset inicial para reanudar importacion}';

    protected $description = 'Sincroniza contratos desde la API Socrata de Catalunya (Generalitat)';

    private int $processed = 0;

    private int $created = 0;

    private int $updated = 0;

    private int $skipped = 0;

    private int $errors = 0;

    private int $noNif = 0;

    public function handle(ContratoImporter $importer): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $full = (bool) $this->option('full');
        $limit = (int) $this->option('limit');
        $startOffset = (int) $this->option('offset');
        $sinceOption = $this->option('since');

        $this->info($dryRun ? 'Modo DRY-RUN: no se insertaran datos.' : 'Sincronizando contratos de Catalunya...');

        $startTime = microtime(true);

        $fuenteDatosId = null;
        $fuente = FuenteDatos::where('slug', 'cat-contractacio')->first();

        if (! $fuente) {
            $this->error('Fuente de datos "cat-contractacio" no encontrada. Ejecuta: php artisan db:seed');

            return self::FAILURE;
        }

        if (! $dryRun) {
            $fuenteDatosId = $fuente->id;
        }

        // Determinar modo: incremental (por defecto) o full
        $sinceDate = $this->resolveSinceDate($sinceOption, $full, $fuente);
        $mode = $sinceDate ? 'incremental' : 'full';

        if ($sinceDate) {
            $this->info("Modo incremental: registros actualizados desde {$sinceDate}");
        } else {
            $this->warn('Modo FULL: descargando todo el dataset');
        }

        $parser = new CatalunyaParser;
        $apiUrl = config('contratacion.regional.catalunya.api_url');
        $pageSize = (int) config('contratacion.regional.catalunya.page_size', 10000);

        $offset = $startOffset;
        $totalFetched = 0;
        $consecutiveErrors = 0;

        while (true) {
            $this->line("Descargando pagina offset={$offset} (limit={$pageSize})...");

            $params = [
                '$limit' => $pageSize,
                '$offset' => $offset,
            ];

            if ($sinceDate) {
                // Incremental: filtrar por fecha + ordenar por updated_at
                $params['$where'] = ":updated_at > '{$sinceDate}'";
                $params['$order'] = ':updated_at DESC';
            } else {
                // Full: orden estable por ID
                $params['$order'] = ':id';
            }

            $response = Http::timeout(120)
                ->retry(3, 3000)
                ->get($apiUrl, $params);

            if (! $response->successful()) {
                $consecutiveErrors++;
                $this->error("Error HTTP {$response->status()} al descargar offset={$offset}");

                if ($consecutiveErrors >= 3) {
                    $this->error('Demasiados errores consecutivos. Abortando.');
                    break;
                }

                // Esperar y reintentar con el mismo offset
                sleep(5);

                continue;
            }

            $consecutiveErrors = 0;
            $records = $response->json();

            if (! is_array($records) || count($records) === 0) {
                $this->info('No hay mas registros.');
                break;
            }

            $count = count($records);
            $totalFetched += $count;
            $this->line("  Recibidos {$count} registros (total descargados: {$totalFetched})");

            foreach ($records as $record) {
                if ($limit > 0 && $this->processed >= $limit) {
                    $this->info("Limite de {$limit} registros alcanzado.");
                    break 2;
                }

                $this->processRecord($record, $parser, $importer, $fuenteDatosId, $dryRun);

                if ($this->processed > 0 && $this->processed % 5000 === 0) {
                    $this->line("  ... {$this->processed} procesados ({$this->created} nuevos, {$this->updated} actualizados, {$this->skipped} sin cambios, {$this->noNif} sin NIF, {$this->errors} errores)");
                }
            }

            // Si recibimos menos del page_size, hemos terminado
            if ($count < $pageSize) {
                break;
            }

            $offset += $pageSize;

            // Pausa entre páginas para no saturar la API
            usleep(500_000);
        }

        $duration = (int) (microtime(true) - $startTime);

        if (! $dryRun && $fuenteDatosId) {
            ImportLog::create([
                'fuente_datos_id' => $fuenteDatosId,
                'tipo' => 'sync-cat',
                'procesados' => $this->processed,
                'nuevos' => $this->created,
                'actualizados' => $this->updated,
                'ignorados' => $this->skipped,
                'errores' => $this->errors,
                'duracion_segundos' => $duration,
                'notas' => "Socrata API mode={$mode}"
                    .($sinceDate ? " since={$sinceDate}" : '')
                    .($startOffset > 0 ? " offset={$startOffset}" : '')
                    .($limit > 0 ? " limit={$limit}" : ''),
            ]);

            FuenteDatos::where('slug', 'cat-contractacio')
                ->update(['ultima_sincronizacion' => now()]);
        }

        $this->showSummary($duration, $dryRun, $mode, $sinceDate);

        return self::SUCCESS;
    }

    /**
     * Determina la fecha desde la que sincronizar.
     * Retorna null para full sync, o una fecha ISO para incremental.
     */
    private function resolveSinceDate(?string $sinceOption, bool $full, FuenteDatos $fuente): ?string
    {
        // --full siempre descarga todo
        if ($full) {
            return null;
        }

        // --since=FECHA tiene prioridad
        if ($sinceOption !== null && $sinceOption !== '') {
            return Carbon::parse($sinceOption)->toIso8601String();
        }

        // Buscar ultimo sync exitoso con registros procesados
        $lastLog = ImportLog::where('fuente_datos_id', $fuente->id)
            ->where('tipo', 'sync-cat')
            ->where('procesados', '>', 0)
            ->latest()
            ->first();

        if ($lastLog) {
            // Retroceder 1 día como margen de seguridad
            return Carbon::parse($lastLog->created_at)
                ->subDay()
                ->toIso8601String();
        }

        // Si hay ultima_sincronizacion en fuente
        if ($fuente->ultima_sincronizacion) {
            return Carbon::parse($fuente->ultima_sincronizacion)
                ->subDay()
                ->toIso8601String();
        }

        // Sin historial → full sync
        $this->warn('No hay historial de sync. Se hara descarga completa.');

        return null;
    }

    private function processRecord(
        array $record,
        CatalunyaParser $parser,
        ContratoImporter $importer,
        ?int $fuenteDatosId,
        bool $dryRun
    ): void {
        $this->processed++;

        try {
            $data = $parser->parse($record);

            if ($data === null) {
                $this->noNif++;
                $this->skipped++;

                return;
            }

            if (empty($data['nif_adjudicatario'])) {
                $this->noNif++;
            }

            $data['fuente_datos_id'] = $fuenteDatosId;
            $data['tipo_registro'] = 'licitacion';

            if ($dryRun) {
                $this->created++;

                return;
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
                $this->warn("Error en registro: {$e->getMessage()}");
            }
        }
    }

    private function showSummary(int $duration, bool $dryRun, string $mode, ?string $sinceDate): void
    {
        $prefix = $dryRun ? '[DRY-RUN] ' : '';

        $this->newLine();
        $this->info("{$prefix}Resumen sincronizacion Catalunya ({$mode}):");
        $this->table(
            ['Metrica', 'Valor'],
            [
                ['Modo', $mode.($sinceDate ? " (desde {$sinceDate})" : '')],
                ['Procesados', number_format($this->processed)],
                ['Nuevos', number_format($this->created)],
                ['Actualizados', number_format($this->updated)],
                ['Sin cambios', number_format($this->skipped)],
                ['Sin NIF adj.', number_format($this->noNif)],
                ['Errores', number_format($this->errors)],
                ['Duracion', "{$duration}s"],
            ]
        );
    }
}
