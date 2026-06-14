<?php

declare(strict_types=1);

namespace App\Services;

use App\Actions\NormalizeName;
use App\Models\Adjudicatario;
use App\Models\AdjudicatarioAlias;
use App\Models\Contrato;
use App\Models\ContratoHistorial;
use App\Models\Organismo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ContratoImporter
{
    private NormalizeName $normalizeName;

    /** @var array<string, Organismo> NIF → Organismo cache for current execution */
    private array $organismoCache = [];

    /** @var array<string, Adjudicatario> NIF → Adjudicatario cache for current execution */
    private array $adjudicatarioCache = [];

    public function __construct(NormalizeName $normalizeName)
    {
        $this->normalizeName = $normalizeName;
    }

    /**
     * Reset in-memory caches. Useful between independent batch runs in the same process.
     */
    public function resetCache(): void
    {
        $this->organismoCache = [];
        $this->adjudicatarioCache = [];
    }

    /**
     * Importa un contrato a partir de un array de datos.
     *
     * @param  array<string, mixed>  $data
     * @return 'created'|'updated'|'skipped'
     */
    public function import(array $data): string
    {
        $organismo = $this->resolveOrganismo($data);

        $adjudicatario = null;
        if (! empty($data['nif_adjudicatario'])) {
            $adjudicatario = $this->resolveAdjudicatario($data);
        }

        return $this->upsertContrato($data, $organismo, $adjudicatario);
    }

    /**
     * Importa un lote de contratos de forma eficiente.
     *
     * Optimiza la resolución de organismos y adjudicatarios reutilizando la caché
     * de instancia. Para los contratos nuevos (no existentes por placsp_id) hace una
     * sola consulta whereIn para discriminarlos de los existentes, evitando un SELECT
     * individual por fila. Los contratos existentes con cambios pasan por el flujo de
     * update/versionado individual (preservando hash_contenido y historial).
     *
     * @param  array<int, array<string, mixed>>  $filas
     * @return array{created: int, updated: int, skipped: int}
     */
    public function importBatch(array $filas): array
    {
        $counts = ['created' => 0, 'updated' => 0, 'skipped' => 0];

        if (empty($filas)) {
            return $counts;
        }

        // Step 1: resolve all organismos/adjudicatarios upfront (cache kicks in for repeated NIFs)
        $resolved = [];
        foreach ($filas as $data) {
            $organismo = $this->resolveOrganismo($data);
            $adjudicatario = null;
            if (! empty($data['nif_adjudicatario'])) {
                $adjudicatario = $this->resolveAdjudicatario($data);
            }
            $resolved[] = ['data' => $data, 'organismo' => $organismo, 'adjudicatario' => $adjudicatario];
        }

        // Step 2: single query to find which placsp_ids already exist
        $placspIds = array_map(fn ($r) => $r['data']['placsp_id'], $resolved);
        $existingContratos = Contrato::whereIn('placsp_id', $placspIds)
            ->get()
            ->keyBy('placsp_id');

        // Step 3: process each row — batch-insert new ones, individual update for existing
        $toInsert = [];

        foreach ($resolved as $row) {
            $data = $row['data'];
            $organismo = $row['organismo'];
            $adjudicatario = $row['adjudicatario'];
            $placspId = $data['placsp_id'];

            $contratoData = $this->buildContratoData($data, $organismo, $adjudicatario);

            if (! $existingContratos->has($placspId)) {
                // New contract — queue for batch insert
                $toInsert[] = array_merge(['placsp_id' => $placspId], $contratoData);
            } else {
                // Existing contract — check hash and update individually (preserves versioning/historial)
                $contrato = $existingContratos->get($placspId);
                $newHash = $this->computeHash($contratoData);

                if ($contrato->hash_contenido === $newHash) {
                    $counts['skipped']++;
                } else {
                    DB::transaction(function () use ($contrato, $placspId, $contratoData, $newHash) {
                        ContratoHistorial::create([
                            'contrato_id' => $contrato->id,
                            'placsp_id' => $placspId,
                            'datos_json' => json_encode($contrato->toArray()),
                            'fecha_updated' => $contrato->fecha_updated ?? $contrato->updated_at,
                        ]);

                        $contrato->update(array_merge($contratoData, [
                            'hash_contenido' => $newHash,
                            'version' => $contrato->version + 1,
                            'fecha_updated' => Carbon::now(),
                        ]));
                    });

                    $counts['updated']++;
                }
            }
        }

        // Step 4: batch-insert all new contracts
        if (! empty($toInsert)) {
            foreach ($toInsert as $row) {
                Contrato::create($row);
                $counts['created']++;
            }
        }

        return $counts;
    }

    private function normalizeNif(string $nif): string
    {
        return mb_strtoupper(trim($nif));
    }

    private function detectTipoIdentificador(string $nif, string $pais): string
    {
        if ($pais !== 'ES') {
            // Identificadores intracomunitarios suelen empezar con código país
            if (preg_match('/^[A-Z]{2}\d/', $nif)) {
                return 'VAT';
            }

            return 'OTHER';
        }

        // NIE: empieza por X, Y o Z
        if (preg_match('/^[XYZ]\d/', $nif)) {
            return 'NIE';
        }

        return 'NIF';
    }

    private function resolveOrganismo(array $data): Organismo
    {
        $nif = $this->normalizeNif($data['nif_organo']);
        $nombre = $data['nombre_organo'] ?? $nif;

        // Return early from cache if already resolved in this execution
        if (isset($this->organismoCache[$nif])) {
            $organismo = $this->organismoCache[$nif];

            // Still attempt enrichment for cached instances (e.g. NUTS may now be available)
            $updates = $this->buildOrganismoEnrichUpdates($organismo, $data);
            if (! empty($updates)) {
                $organismo->update($updates);
                // Cache stays consistent because we updated the model in-place via Eloquent
            }

            return $organismo;
        }

        $organismo = Organismo::firstOrCreate(
            ['nif' => $nif],
            [
                'nombre' => $nombre,
                'nombre_normalizado' => ($this->normalizeName)($nombre),
                'dir3' => $data['dir3'] ?? null,
                'id_plataforma' => $data['id_plataforma'] ?? null,
                'tipo' => $data['tipo_organo'] ?? null,
                'url_perfil_placsp' => $data['url_perfil_placsp'] ?? null,
                'codigo_actividad' => $data['codigo_actividad'] ?? null,
                'contacto_nombre' => $data['contacto_nombre'] ?? null,
                'contacto_email' => $data['contacto_email'] ?? null,
                'contacto_telefono' => $data['contacto_telefono'] ?? null,
                'direccion' => $data['direccion_organo'] ?? null,
                'ciudad' => $data['ciudad_organo'] ?? null,
                'codigo_postal' => $data['codigo_postal_organo'] ?? null,
            ]
        );

        // Enriquecer campos vacíos con datos nuevos
        $updates = $this->buildOrganismoEnrichUpdates($organismo, $data);

        if (! empty($updates)) {
            $organismo->update($updates);
        }

        $this->organismoCache[$nif] = $organismo;

        return $organismo;
    }

    /**
     * Build an array of enrich-only updates for an Organismo (no overwrite of existing values).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function buildOrganismoEnrichUpdates(Organismo $organismo, array $data): array
    {
        $updates = [];

        // Enriquecer NUTS del organismo desde el NUTS del contrato
        if (empty($organismo->nuts) && ! empty($data['nuts'])) {
            $nutsContrato = $data['nuts'];
            // Tomar los primeros 4 chars (NUTS2 = CCAA) como NUTS del organismo
            if (mb_strlen($nutsContrato) >= 4) {
                $updates['nuts'] = mb_substr($nutsContrato, 0, 4);
            }
        }

        $enrichFields = [
            'dir3' => 'dir3',
            'id_plataforma' => 'id_plataforma',
            'tipo' => 'tipo_organo',
            'url_perfil_placsp' => 'url_perfil_placsp',
            'codigo_actividad' => 'codigo_actividad',
            'contacto_nombre' => 'contacto_nombre',
            'contacto_email' => 'contacto_email',
            'contacto_telefono' => 'contacto_telefono',
            'direccion' => 'direccion_organo',
            'ciudad' => 'ciudad_organo',
            'codigo_postal' => 'codigo_postal_organo',
        ];

        foreach ($enrichFields as $modelField => $dataField) {
            if (empty($organismo->$modelField) && ! empty($data[$dataField])) {
                $updates[$modelField] = $data[$dataField];
            }
        }

        return $updates;
    }

    private function resolveAdjudicatario(array $data): Adjudicatario
    {
        $nif = $this->normalizeNif($data['nif_adjudicatario']);
        $nombre = $data['nombre_adjudicatario'] ?? $nif;

        if (isset($this->adjudicatarioCache[$nif])) {
            $adjudicatario = $this->adjudicatarioCache[$nif];
            // Always register alias even for cached adjudicatarios
            $this->registerAlias($adjudicatario, $nombre);

            return $adjudicatario;
        }

        $pais = $data['pais_adjudicatario'] ?? 'ES';
        $tipoId = $this->detectTipoIdentificador($nif, $pais);

        $adjudicatario = Adjudicatario::firstOrCreate(
            ['nif' => $nif],
            [
                'nombre' => $nombre,
                'nombre_normalizado' => ($this->normalizeName)($nombre),
                'es_pyme' => $data['es_pyme'] ?? null,
                'pais' => $pais,
                'tipo_identificador' => $tipoId,
            ]
        );

        $this->registerAlias($adjudicatario, $nombre);

        $this->adjudicatarioCache[$nif] = $adjudicatario;

        return $adjudicatario;
    }

    private function registerAlias(Adjudicatario $adjudicatario, string $nombre): void
    {
        $now = Carbon::now();

        $alias = AdjudicatarioAlias::where('adjudicatario_id', $adjudicatario->id)
            ->where('nombre_variante', $nombre)
            ->first();

        if ($alias) {
            $alias->update([
                'veces_visto' => $alias->veces_visto + 1,
                'ultima_vez' => $now,
            ]);
        } else {
            AdjudicatarioAlias::create([
                'adjudicatario_id' => $adjudicatario->id,
                'nombre_variante' => $nombre,
                'veces_visto' => 1,
                'primera_vez' => $now,
                'ultima_vez' => $now,
            ]);
        }
    }

    /**
     * @return 'created'|'updated'|'skipped'
     */
    private function upsertContrato(array $data, Organismo $organismo, ?Adjudicatario $adjudicatario): string
    {
        $placspId = $data['placsp_id'];
        $contrato = Contrato::where('placsp_id', $placspId)->first();

        $contratoData = $this->buildContratoData($data, $organismo, $adjudicatario);

        if (! $contrato) {
            Contrato::create(array_merge(['placsp_id' => $placspId], $contratoData));

            return 'created';
        }

        // Comprobar si ha cambiado mediante hash
        $newHash = $this->computeHash($contratoData);

        if ($contrato->hash_contenido === $newHash) {
            return 'skipped';
        }

        DB::transaction(function () use ($contrato, $placspId, $contratoData, $newHash) {
            // Guardar versión anterior en historial
            ContratoHistorial::create([
                'contrato_id' => $contrato->id,
                'placsp_id' => $placspId,
                'datos_json' => json_encode($contrato->toArray()),
                'fecha_updated' => $contrato->fecha_updated ?? $contrato->updated_at,
            ]);

            // Actualizar contrato
            $contrato->update(array_merge($contratoData, [
                'hash_contenido' => $newHash,
                'version' => $contrato->version + 1,
                'fecha_updated' => Carbon::now(),
            ]));
        });

        return 'updated';
    }

    /**
     * @return array<string, mixed>
     */
    private function buildContratoData(array $data, Organismo $organismo, ?Adjudicatario $adjudicatario): array
    {
        $contratoData = [
            'organismo_id' => $organismo->id,
            'adjudicatario_id' => $adjudicatario?->id,
            'nif_organo' => isset($data['nif_organo']) ? $this->normalizeNif($data['nif_organo']) : null,
            'nif_adjudicatario' => isset($data['nif_adjudicatario']) ? $this->normalizeNif($data['nif_adjudicatario']) : null,
            'pais_adjudicatario' => $data['pais_adjudicatario'] ?? null,
            'nombre_adjudicatario' => $data['nombre_adjudicatario'] ?? null,
            'expediente' => $data['expediente'] ?? null,
            'numero_lote' => $data['numero_lote'] ?? null,
            'url_placsp' => $data['url_placsp'] ?? null,
            'url_xml' => $data['url_xml'] ?? null,
            'uuid_ted' => $data['uuid_ted'] ?? null,
            'id_plataforma' => $data['id_plataforma'] ?? null,
            'objeto' => isset($data['objeto']) ? html_entity_decode($data['objeto'], ENT_QUOTES | ENT_HTML5, 'UTF-8') : null,
            'tipo_contrato' => $data['tipo_contrato'] ?? null,
            'subtipo_contrato' => $data['subtipo_contrato'] ?? null,
            'procedimiento' => $data['procedimiento'] ?? null,
            'urgencia' => $data['urgencia'] ?? null,
            'estado' => $data['estado'] ?? null,
            'resultado_codigo' => $data['resultado_codigo'] ?? null,
            'importe_licitacion' => $data['importe_licitacion'] ?? null,
            'importe_licitacion_con_iva' => $data['importe_licitacion_con_iva'] ?? null,
            'importe_estimado' => $data['importe_estimado'] ?? null,
            'importe_adjudicacion' => $data['importe_adjudicacion'] ?? null,
            'importe_adjudicacion_con_iva' => $data['importe_adjudicacion_con_iva'] ?? null,
            'moneda' => $data['moneda'] ?? 'EUR',
            'duracion' => $data['duracion'] ?? null,
            'cpv' => $data['cpv'] ?? null,
            'nuts' => $data['nuts'] ?? null,
            'lugar_ejecucion' => $data['lugar_ejecucion'] ?? null,
            'ciudad_ejecucion' => $data['ciudad_ejecucion'] ?? null,
            'codigo_postal_ejecucion' => $data['codigo_postal_ejecucion'] ?? null,
            'num_ofertas' => $data['num_ofertas'] ?? null,
            'criterios_adjudicacion' => isset($data['criterios_adjudicacion']) ? json_encode($data['criterios_adjudicacion']) : null,
            'es_menor' => $data['es_menor'] ?? false,
            'es_clm' => str_starts_with($data['nuts'] ?? '', 'ES42'),
            'financiacion_ue' => $data['financiacion_ue'] ?? null,
            'fecha_publicacion' => $data['fecha_publicacion'] ?? null,
            'fecha_limite' => $data['fecha_limite'] ?? null,
            'hora_limite' => $data['hora_limite'] ?? null,
            'fecha_adjudicacion' => $data['fecha_adjudicacion'] ?? null,
            'fecha_formalizacion' => $data['fecha_formalizacion'] ?? null,
            'fuente_datos_id' => $data['fuente_datos_id'] ?? null,
            'tipo_registro' => $data['tipo_registro'] ?? 'licitacion',
        ];

        $contratoData['hash_contenido'] = $this->computeHash($contratoData);

        return $contratoData;
    }

    private function computeHash(array $data): string
    {
        // Excluir campos que cambian sin ser datos reales del contrato
        $exclude = ['hash_contenido', 'organismo_id', 'adjudicatario_id', 'fuente_datos_id'];
        $hashData = array_diff_key($data, array_flip($exclude));
        ksort($hashData);

        $json = json_encode($hashData);

        return hash('sha256', $json !== false ? $json : serialize($hashData));
    }
}
