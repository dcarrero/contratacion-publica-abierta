<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\FuenteDatos;
use App\Models\ImportLog;
use App\Services\ContratoImporter;
use Illuminate\Console\Command;
use League\Csv\Reader;

class BootstrapFromCsv extends Command
{
    protected $signature = 'bootstrap:csv
        {file : Ruta al fichero CSV}
        {--chunk=500 : Registros por chunk}
        {--dry-run : Parsear y validar sin insertar}';

    protected $description = 'Importa contratos desde un CSV de BQuant bootstrap';

    /**
     * Mapeo: columna CSV BQuant → clave esperada por ContratoImporter.
     *
     * @var array<string, string>
     */
    private const CSV_FIELD_MAP = [
        'id' => 'placsp_id',
        'expediente' => 'expediente',
        'objeto' => 'objeto',
        'url' => 'url_placsp',
        'organo_contratante' => 'nombre_organo',
        'nif_organo' => 'nif_organo',
        'dir3_organo' => 'dir3',
        'adjudicatario' => 'nombre_adjudicatario',
        'nif_adjudicatario' => 'nif_adjudicatario',
        'es_pyme' => 'es_pyme',
        'tipo_contrato' => 'tipo_contrato',
        'procedimiento' => 'procedimiento',
        'estado' => 'estado',
        'importe_sin_iva' => 'importe_licitacion',
        'importe_con_iva' => 'importe_licitacion_con_iva',
        'importe_adjudicacion' => 'importe_adjudicacion',
        'importe_adjudicacion_con_iva' => 'importe_adjudicacion_con_iva',
        'nuts' => 'nuts',
        'cpv_principal' => 'cpv',
        'ubicacion' => 'lugar_ejecucion',
        'num_ofertas' => 'num_ofertas',
        'fecha_publicacion' => 'fecha_publicacion',
        'fecha_limite' => 'fecha_limite',
        'fecha_adjudicacion' => 'fecha_adjudicacion',
        'fecha_updated' => 'fecha_updated',
        'duracion' => 'duracion',
    ];

    private int $processed = 0;

    private int $created = 0;

    private int $updated = 0;

    private int $skipped = 0;

    private int $errors = 0;

    private int $warnings = 0;

    public function handle(ContratoImporter $importer): int
    {
        $file = $this->argument('file');
        $chunkSize = (int) $this->option('chunk');
        $dryRun = (bool) $this->option('dry-run');

        if (! file_exists($file)) {
            $this->error("Fichero no encontrado: {$file}");

            return self::FAILURE;
        }

        $this->info($dryRun ? 'Modo DRY-RUN: no se insertaran datos.' : 'Importando datos...');
        $this->info("Fichero: {$file}");

        $startTime = microtime(true);

        $reader = Reader::createFromPath($file, 'r');
        $reader->setHeaderOffset(0);
        $headers = $reader->getHeader();

        $this->info('Columnas detectadas: '.implode(', ', $headers));

        $fuenteDatosId = null;
        if (! $dryRun) {
            $fuente = FuenteDatos::where('slug', 'bquant-bootstrap')->first();
            if (! $fuente) {
                $this->error('Fuente de datos "bquant-bootstrap" no encontrada. Ejecuta: php artisan db:seed');

                return self::FAILURE;
            }
            $fuenteDatosId = $fuente->id;
        }

        $records = $reader->getRecords();
        $totalRecords = $reader->count();

        $bar = $this->output->createProgressBar($totalRecords);
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% | %elapsed:6s% | Creados: %created% | Errores: %errors%');
        $bar->setMessage('0', 'created');
        $bar->setMessage('0', 'errors');
        $bar->start();

        $chunk = [];

        foreach ($records as $record) {
            $chunk[] = $record;

            if (count($chunk) >= $chunkSize) {
                $this->processChunk($chunk, $importer, $fuenteDatosId, $dryRun);
                $chunk = [];
                $bar->setMessage((string) $this->created, 'created');
                $bar->setMessage((string) $this->errors, 'errors');
                $bar->advance($chunkSize);
            }
        }

        // Procesar resto
        if (count($chunk) > 0) {
            $this->processChunk($chunk, $importer, $fuenteDatosId, $dryRun);
            $bar->setMessage((string) $this->created, 'created');
            $bar->setMessage((string) $this->errors, 'errors');
            $bar->advance(count($chunk));
        }

        $bar->finish();
        $this->newLine(2);

        $duration = (int) (microtime(true) - $startTime);

        // Registrar ImportLog
        if (! $dryRun && $fuenteDatosId) {
            ImportLog::create([
                'fuente_datos_id' => $fuenteDatosId,
                'tipo' => 'bootstrap',
                'procesados' => $this->processed,
                'nuevos' => $this->created,
                'actualizados' => $this->updated,
                'ignorados' => $this->skipped,
                'errores' => $this->errors,
                'duracion_segundos' => $duration,
                'notas' => "Bootstrap CSV: {$file}",
            ]);
        }

        $this->showSummary($duration, $dryRun);

        return self::SUCCESS;
    }

    /**
     * @param  array<int, array<string, string>>  $chunk
     */
    private function processChunk(array $chunk, ContratoImporter $importer, ?int $fuenteDatosId, bool $dryRun): void
    {
        foreach ($chunk as $record) {
            $this->processed++;

            $mapped = $this->mapRecord($record);

            if (empty($mapped['nif_organo'])) {
                $this->warnings++;
                $this->warn("Fila {$this->processed}: sin nif_organo, saltando.");
                $this->skipped++;

                continue;
            }

            // Generar placsp_id si no existe
            if (empty($mapped['placsp_id'])) {
                $mapped['placsp_id'] = 'BQ-'.($mapped['expediente'] ?? 'NOEXP').'-'.($mapped['nif_organo'] ?? 'NONIF');
            }

            // Asignar fuente de datos y flags
            $mapped['fuente_datos_id'] = $fuenteDatosId;
            $mapped['tipo_registro'] = 'licitacion';

            if ($dryRun) {
                $this->created++;

                continue;
            }

            try {
                $result = $importer->import($mapped);

                match ($result) {
                    'created' => $this->created++,
                    'updated' => $this->updated++,
                    'skipped' => $this->skipped++,
                };
            } catch (\Throwable $e) {
                $this->errors++;
                $this->warn("Fila {$this->processed}: {$e->getMessage()}");
            }
        }
    }

    /**
     * Mapea un registro CSV a las claves esperadas por ContratoImporter.
     *
     * @param  array<string, string>  $record
     * @return array<string, mixed>
     */
    private function mapRecord(array $record): array
    {
        $mapped = [];

        foreach (self::CSV_FIELD_MAP as $csvKey => $importerKey) {
            $value = $record[$csvKey] ?? null;

            // Limpiar valores vacíos
            if ($value === '' || $value === null) {
                $mapped[$importerKey] = null;

                continue;
            }

            $mapped[$importerKey] = $value;
        }

        // Combinar duración con unidad si existe
        if (! empty($record['duracion_unidad']) && ! empty($mapped['duracion'])) {
            $mapped['duracion'] = $mapped['duracion'].' '.$record['duracion_unidad'];
        }

        // Convertir es_pyme a booleano
        if (isset($mapped['es_pyme'])) {
            $mapped['es_pyme'] = in_array(strtolower((string) $mapped['es_pyme']), ['true', '1', 'si', 'yes', 'sí'], true);
        }

        // Convertir num_ofertas a integer
        if (isset($mapped['num_ofertas']) && $mapped['num_ofertas'] !== null) {
            $mapped['num_ofertas'] = (int) $mapped['num_ofertas'];
        }

        // Convertir importes a float
        foreach (['importe_licitacion', 'importe_licitacion_con_iva', 'importe_adjudicacion', 'importe_adjudicacion_con_iva'] as $field) {
            if (isset($mapped[$field]) && $mapped[$field] !== null) {
                $mapped[$field] = (float) $mapped[$field];
            }
        }

        return $mapped;
    }

    private function showSummary(int $duration, bool $dryRun): void
    {
        $prefix = $dryRun ? '[DRY-RUN] ' : '';

        $this->info("{$prefix}Resumen de importacion:");
        $this->table(
            ['Metrica', 'Valor'],
            [
                ['Procesados', number_format($this->processed)],
                ['Nuevos', number_format($this->created)],
                ['Actualizados', number_format($this->updated)],
                ['Sin cambios', number_format($this->skipped)],
                ['Errores', number_format($this->errors)],
                ['Avisos', number_format($this->warnings)],
                ['Duracion', "{$duration}s"],
            ]
        );
    }
}
