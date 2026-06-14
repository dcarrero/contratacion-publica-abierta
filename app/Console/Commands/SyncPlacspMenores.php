<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\FuenteDatos;
use App\Models\ImportLog;
use App\Services\ContratoImporter;
use App\Services\Placsp\AtomFeedReader;
use App\Services\Placsp\PlacspEntryParser;
use Illuminate\Console\Command;

class SyncPlacspMenores extends Command
{
    protected $signature = 'placsp:sync-menores
        {--full : Procesar todo el feed sin limite temporal}
        {--since= : Fecha ISO desde la que sincronizar (override)}
        {--max-pages=100 : Limite de paginas a procesar}
        {--ccaa= : Filtrar por CCAA (codigo NUTS2, ej: ES42)}
        {--dry-run : Parsear y mostrar sin insertar datos}';

    protected $description = 'Sincroniza contratos menores desde el feed Atom de PLACSP';

    private int $processed = 0;

    private int $created = 0;

    private int $updated = 0;

    private int $skipped = 0;

    private int $errors = 0;

    private int $consecutiveErrors = 0;

    private int $maxConsecutiveErrors;

    private ?string $newestEntryDate = null;

    public function handle(ContratoImporter $importer, PlacspEntryParser $parser): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $maxPages = (int) $this->option('max-pages');

        $this->maxConsecutiveErrors = (int) config('contratacion.placsp_sync.max_consecutive_errors', 50);

        $this->info($dryRun ? 'Modo DRY-RUN: no se insertaran datos.' : 'Sincronizando contratos menores PLACSP...');

        $startTime = microtime(true);

        // Resolver fuente de datos
        $fuenteDatosId = null;
        if (! $dryRun) {
            $fuente = FuenteDatos::where('slug', 'placsp-menores')->first();
            if (! $fuente) {
                $this->error('Fuente de datos "placsp-menores" no encontrada. Ejecuta: php artisan db:seed');

                return self::FAILURE;
            }
            $fuenteDatosId = $fuente->id;
        }

        // Determinar fecha desde
        $since = $this->resolveSince($fuenteDatosId);

        if ($since !== null) {
            $this->info("Sincronizando desde: {$since->format('Y-m-d H:i:s')}");
        } else {
            $this->info('Sincronizacion completa (sin limite temporal).');
        }

        // Configurar reader
        $feedUrl = config('contratacion.placsp.menores_feed');
        $reader = new AtomFeedReader($feedUrl, [
            'max_pages' => $maxPages,
        ]);

        // Iterar entries
        $entries = $since !== null && ! $this->option('full')
            ? $reader->entriesSince($since)
            : $reader->entries();

        foreach ($entries as $entry) {
            if ($this->consecutiveErrors >= $this->maxConsecutiveErrors) {
                $this->error("Demasiados errores consecutivos ({$this->consecutiveErrors}). Abortando.");
                break;
            }

            // Registrar fecha del primer entry (más reciente del feed)
            if ($this->newestEntryDate === null) {
                $updated = (string) $entry->updated;
                if ($updated !== '') {
                    $this->newestEntryDate = $updated;
                }
            }

            $this->processEntry($entry, $parser, $importer, $fuenteDatosId, $dryRun);

            // Progreso cada 100 entries procesados
            if ($this->processed > 0 && $this->processed % 100 === 0) {
                $this->line("  ... {$this->processed} entries procesados ({$this->created} nuevos, {$this->updated} actualizados, {$this->errors} errores)");
            }
        }

        // Detección de feed obsoleto
        $this->checkStaleFeed();

        $duration = (int) (microtime(true) - $startTime);

        // Registrar ImportLog
        if (! $dryRun && $fuenteDatosId) {
            ImportLog::create([
                'fuente_datos_id' => $fuenteDatosId,
                'tipo' => 'sync-menores',
                'procesados' => $this->processed,
                'nuevos' => $this->created,
                'actualizados' => $this->updated,
                'ignorados' => $this->skipped,
                'errores' => $this->errors,
                'duracion_segundos' => $duration,
                'notas' => $since ? "Incremental desde {$since->format('Y-m-d H:i:s')}" : 'Full sync',
            ]);

            // Actualizar última sincronización
            FuenteDatos::where('slug', 'placsp-menores')
                ->update(['ultima_sincronizacion' => now()]);
        }

        $this->showSummary($duration, $dryRun);

        return self::SUCCESS;
    }

    private function processEntry(
        \SimpleXMLElement $entry,
        PlacspEntryParser $parser,
        ContratoImporter $importer,
        ?int $fuenteDatosId,
        bool $dryRun
    ): void {
        $this->processed++;

        try {
            $data = $parser->parse($entry);

            if ($data === null) {
                $this->skipped++;

                return;
            }

            if (empty($data['nif_organo'])) {
                $this->skipped++;

                return;
            }

            // Filtro opcional por CCAA
            $ccaaFilter = $this->option('ccaa');
            if ($ccaaFilter && ! str_starts_with($data['nuts'] ?? '', $ccaaFilter)) {
                $this->skipped++;

                return;
            }

            // Flags específicos de menores
            $data['es_menor'] = true;
            $data['tipo_registro'] = 'menor';
            $data['fuente_datos_id'] = $fuenteDatosId;

            if ($dryRun) {
                $this->created++;
                $this->consecutiveErrors = 0;

                return;
            }

            $result = $importer->import($data);

            match ($result) {
                'created' => $this->created++,
                'updated' => $this->updated++,
                'skipped' => $this->skipped++,
            };

            $this->consecutiveErrors = 0;

        } catch (\Throwable $e) {
            $this->errors++;
            $this->consecutiveErrors++;

            $entryId = (string) $entry->id;
            $this->warn("Error en entry {$entryId}: {$e->getMessage()}");
        }
    }

    private function resolveSince(?int $fuenteDatosId): ?\DateTimeInterface
    {
        // 1. Override manual
        $sinceOption = $this->option('since');
        if ($sinceOption !== null) {
            try {
                return new \DateTimeImmutable($sinceOption);
            } catch (\Exception) {
                $this->warn("Fecha --since invalida: {$sinceOption}, usando full sync.");

                return null;
            }
        }

        // 2. Full sync explícito
        if ($this->option('full')) {
            return null;
        }

        // 3. Último ImportLog exitoso
        if ($fuenteDatosId !== null) {
            $lastLog = ImportLog::where('fuente_datos_id', $fuenteDatosId)
                ->where('tipo', 'sync-menores')
                ->latest()
                ->first();

            if ($lastLog) {
                return $lastLog->created_at;
            }
        }

        // 4. Última sincronización de la fuente
        if ($fuenteDatosId !== null) {
            $fuente = FuenteDatos::find($fuenteDatosId);
            if ($fuente?->ultima_sincronizacion) {
                return $fuente->ultima_sincronizacion;
            }
        }

        // 5. Full sync
        return null;
    }

    private function checkStaleFeed(): void
    {
        if ($this->newestEntryDate === null) {
            $this->warn('No se encontraron entries en el feed.');

            return;
        }

        try {
            $newest = new \DateTimeImmutable($this->newestEntryDate);
            $daysSinceUpdate = (int) $newest->diff(new \DateTimeImmutable)->days;

            if ($daysSinceUpdate > 7) {
                $this->warn(
                    "ATENCION: El feed no se actualiza desde hace {$daysSinceUpdate} dias "
                    ."(ultima entrada: {$newest->format('Y-m-d H:i:s')}). "
                    .'Posible incidencia en PLACSP.'
                );
            }
        } catch (\Exception) {
            // Fecha no parseable, ignorar
        }
    }

    private function showSummary(int $duration, bool $dryRun): void
    {
        $prefix = $dryRun ? '[DRY-RUN] ' : '';

        $this->newLine();
        $this->info("{$prefix}Resumen sincronizacion menores PLACSP:");
        $this->table(
            ['Metrica', 'Valor'],
            [
                ['Entries procesados', number_format($this->processed)],
                ['Nuevos', number_format($this->created)],
                ['Actualizados', number_format($this->updated)],
                ['Sin cambios', number_format($this->skipped)],
                ['Errores', number_format($this->errors)],
                ['Duracion', "{$duration}s"],
            ]
        );
    }
}
