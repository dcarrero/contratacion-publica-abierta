<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Contrato;
use App\Models\FuenteDatos;
use App\Models\ImportLog;
use App\Services\ContratoImporter;
use App\Services\Placsp\AtomFeedReader;
use App\Services\Placsp\PlacspEntryParser;
use Illuminate\Console\Command;

class SyncMadrid extends Command
{
    protected $signature = 'regional:sync-mad
        {--full : Procesar todo el feed sin limite temporal}
        {--since= : Fecha ISO desde la que sincronizar (override)}
        {--max-pages=50 : Limite de paginas a procesar}
        {--dry-run : Parsear y mostrar sin insertar datos}';

    protected $description = 'Sincroniza licitaciones desde el feed Atom de la Comunidad de Madrid';

    private int $processed = 0;

    private int $created = 0;

    private int $updated = 0;

    private int $skipped = 0;

    private int $errors = 0;

    private int $duplicates = 0;

    private int $consecutiveErrors = 0;

    private int $maxConsecutiveErrors;

    public function handle(ContratoImporter $importer, PlacspEntryParser $parser): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $maxPages = (int) $this->option('max-pages');

        $this->maxConsecutiveErrors = (int) config('contratacion.placsp_sync.max_consecutive_errors', 50);

        $this->info($dryRun ? 'Modo DRY-RUN: no se insertaran datos.' : 'Sincronizando licitaciones Madrid...');

        $startTime = microtime(true);

        // Resolver fuente de datos
        $fuenteDatosId = null;
        if (! $dryRun) {
            $fuente = FuenteDatos::where('slug', 'madrid-contratos')->first();
            if (! $fuente) {
                $this->error('Fuente de datos "madrid-contratos" no encontrada. Ejecuta: php artisan db:seed');

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

        // Configurar reader con URL del feed de Madrid
        $feedUrl = config('contratacion.regional.madrid.feed_url');
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

            $this->processEntry($entry, $parser, $importer, $fuenteDatosId, $dryRun);

            if ($this->processed > 0 && $this->processed % 100 === 0) {
                $this->line("  ... {$this->processed} entries procesados ({$this->created} nuevos, {$this->duplicates} duplicados PLACSP, {$this->errors} errores)");
            }
        }

        $duration = (int) (microtime(true) - $startTime);

        // Registrar ImportLog
        if (! $dryRun && $fuenteDatosId) {
            ImportLog::create([
                'fuente_datos_id' => $fuenteDatosId,
                'tipo' => 'sync-mad',
                'procesados' => $this->processed,
                'nuevos' => $this->created,
                'actualizados' => $this->updated,
                'ignorados' => $this->skipped,
                'errores' => $this->errors,
                'duracion_segundos' => $duration,
                'notas' => 'Madrid ATOM. Duplicados PLACSP: '.$this->duplicates,
            ]);

            FuenteDatos::where('slug', 'madrid-contratos')
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

            // Forzar NUTS de Madrid
            $defaultNuts = config('contratacion.regional.madrid.default_nuts', 'ES30');
            if (empty($data['nuts'])) {
                $data['nuts'] = $defaultNuts;
            }

            // Deduplicación: comprobar si ya existe un contrato con mismo expediente + nif_organo
            if (! empty($data['expediente']) && ! empty($data['nif_organo'])) {
                $exists = Contrato::where('expediente', $data['expediente'])
                    ->whereHas('organismo', fn ($q) => $q->where('nif', $data['nif_organo']))
                    ->exists();

                if ($exists) {
                    $this->duplicates++;
                    $this->skipped++;

                    return;
                }
            }

            // Reescribir placsp_id con prefijo MAD-
            $entryId = (string) $entry->id;
            $data['placsp_id'] = $this->buildMadridPlacspId($entryId);

            $data['es_menor'] = false;
            $data['tipo_registro'] = 'licitacion';
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
            if ($this->errors <= 10) {
                $this->warn("Error en entry {$entryId}: {$e->getMessage()}");
            }
        }
    }

    /**
     * Construye placsp_id con prefijo MAD- a partir del entry ID.
     * Ejemplo: "https://contratos-publicos.comunidad.madrid/feed/licitaciones2/121/entry/291806"
     * → "MAD-291806"
     */
    private function buildMadridPlacspId(string $entryId): string
    {
        $parts = explode('/', rtrim($entryId, '/'));
        $lastPart = end($parts);

        if (is_numeric($lastPart)) {
            return 'MAD-'.$lastPart;
        }

        return 'MAD-'.md5($entryId);
    }

    private function resolveSince(?int $fuenteDatosId): ?\DateTimeInterface
    {
        $sinceOption = $this->option('since');
        if ($sinceOption !== null) {
            try {
                return new \DateTimeImmutable($sinceOption);
            } catch (\Exception) {
                $this->warn("Fecha --since invalida: {$sinceOption}, usando full sync.");

                return null;
            }
        }

        if ($this->option('full')) {
            return null;
        }

        if ($fuenteDatosId !== null) {
            $lastLog = ImportLog::where('fuente_datos_id', $fuenteDatosId)
                ->where('tipo', 'sync-mad')
                ->latest()
                ->first();

            if ($lastLog) {
                return $lastLog->created_at;
            }
        }

        if ($fuenteDatosId !== null) {
            $fuente = FuenteDatos::find($fuenteDatosId);
            if ($fuente?->ultima_sincronizacion) {
                return $fuente->ultima_sincronizacion;
            }
        }

        return null;
    }

    private function showSummary(int $duration, bool $dryRun): void
    {
        $prefix = $dryRun ? '[DRY-RUN] ' : '';

        $this->newLine();
        $this->info("{$prefix}Resumen sincronizacion Madrid:");
        $this->table(
            ['Metrica', 'Valor'],
            [
                ['Entries procesados', number_format($this->processed)],
                ['Nuevos', number_format($this->created)],
                ['Actualizados', number_format($this->updated)],
                ['Duplicados PLACSP', number_format($this->duplicates)],
                ['Sin cambios', number_format($this->skipped)],
                ['Errores', number_format($this->errors)],
                ['Duracion', "{$duration}s"],
            ]
        );
    }
}
