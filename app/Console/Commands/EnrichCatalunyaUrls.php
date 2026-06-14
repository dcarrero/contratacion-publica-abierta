<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Regional\CatalunyaParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class EnrichCatalunyaUrls extends Command
{
    protected $signature = 'regional:enrich-cat
        {--dry-run : Mostrar sin aplicar}
        {--limit=0 : Límite de registros a procesar (0 = todos)}';

    protected $description = 'Enriquece contratos de Catalunya con URL y fecha desde la API Socrata';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');

        $this->info($dryRun ? '[DRY-RUN] Consultando API Socrata...' : 'Enriqueciendo contratos Catalunya...');

        $apiUrl = config('contratacion.regional.catalunya.api_url');
        $pageSize = 10000;
        $offset = 0;
        $updated = 0;
        $urlsAdded = 0;
        $datesAdded = 0;

        $parser = new CatalunyaParser;

        while (true) {
            $this->line("Descargando offset={$offset}...");

            $response = Http::timeout(120)->retry(3, 3000)->get($apiUrl, [
                '$limit' => $pageSize,
                '$offset' => $offset,
                '$select' => 'codi_expedient,codi_dir3,enllac_publicacio,data_publicacio_contracte',
                '$where' => 'enllac_publicacio IS NOT NULL',
                '$order' => ':id',
            ]);

            if (! $response->successful()) {
                $this->error("Error HTTP {$response->status()}");
                break;
            }

            $records = $response->json();
            if (! is_array($records) || count($records) === 0) {
                break;
            }

            foreach ($records as $record) {
                $dir3 = $record['codi_dir3'] ?? null;
                $expediente = $record['codi_expedient'] ?? null;
                if (! $dir3 || ! $expediente) {
                    continue;
                }

                $placspId = "CAT-{$dir3}-{$expediente}";
                $url = $this->extractUrl($record['enllac_publicacio'] ?? null);
                $fecha = $this->parseDate($record['data_publicacio_contracte'] ?? null);

                if (! $url && ! $fecha) {
                    continue;
                }

                $updates = [];
                if ($url) {
                    $updates['url_placsp'] = $url;
                }
                if ($fecha) {
                    // Solo actualizar fecha si la actual es NULL
                    $updates['fecha_publicacion'] = DB::raw(
                        "CASE WHEN fecha_publicacion IS NULL THEN '{$fecha}' ELSE fecha_publicacion END"
                    );
                }

                if (! $dryRun) {
                    $affected = DB::table('contratos')
                        ->where('placsp_id', $placspId)
                        ->where(function ($q) {
                            $q->whereNull('url_placsp')->orWhere('url_placsp', '');
                        })
                        ->update($url ? ['url_placsp' => $url] : []);

                    if ($affected > 0) {
                        $urlsAdded += $affected;
                    }

                    if ($fecha) {
                        $dateAffected = DB::table('contratos')
                            ->where('placsp_id', $placspId)
                            ->whereNull('fecha_publicacion')
                            ->update(['fecha_publicacion' => $fecha]);
                        $datesAdded += $dateAffected;
                    }
                } else {
                    $urlsAdded++;
                }

                $updated++;

                if ($limit > 0 && $updated >= $limit) {
                    break 2;
                }
            }

            if ($updated > 0 && $updated % 50000 === 0) {
                $this->line("  {$updated} procesados, {$urlsAdded} URLs, {$datesAdded} fechas");
            }

            if (count($records) < $pageSize) {
                break;
            }

            $offset += $pageSize;
            usleep(500_000);
        }

        $this->newLine();
        $this->table(['Métrica', 'Valor'], [
            ['Registros con URL en API', number_format($updated)],
            ['URLs añadidas', number_format($urlsAdded)],
            ['Fechas rellenadas', number_format($datesAdded)],
        ]);

        return self::SUCCESS;
    }

    private function extractUrl(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value) && isset($value['url'])) {
            return $value['url'];
        }

        if (is_string($value) && str_starts_with($value, 'http')) {
            return $value;
        }

        return null;
    }

    private function parseDate(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return substr($value, 0, 10); // YYYY-MM-DD from ISO datetime
        } catch (\Throwable) {
            return null;
        }
    }
}
