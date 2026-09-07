<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Contrato;
use App\Models\FuenteDatos;
use App\Services\Regional\ExistingContractIndex;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Fusiona contratos que ya están duplicados en la base porque llegaron por dos
 * fuentes distintas.
 *
 * POR QUÉ EXISTE
 * --------------
 * La deduplicación de los comandos de importación (ver ExistingContractIndex)
 * evita insertar duplicados nuevos, pero no puede deshacer los que ya están: si
 * importamos un contrato antes de que PLACSP lo publique —el sync municipal de
 * Madrid es mensual y PLACSP publica en continuo— acabamos con las dos copias.
 * Este comando cierra esa vía.
 *
 * CRITERIO DE SUPERVIVENCIA (en este orden, todo determinista)
 * ------------------------------------------------------------
 *  1. Más campos de TRAZABILIDAD rellenos (cpv, nuts, url_placsp, url_xml,
 *     uuid_ted, id_plataforma, expediente). el proyecto exige trazabilidad, y es
 *     justo donde se separan las fuentes: PLACSP trae CPV, NUTS reales y URL
 *     oficial por contrato; los CSV regionales rara vez traen ninguno.
 *  2. Si empatan, más campos informativos rellenos en total.
 *  3. Si empatan, el más antiguo por created_at.
 *  4. Si empatan, el de id menor.
 *
 * Se puntúa por campos rellenos en vez de por un ranking fijo de fuentes: el
 * resultado es el mismo que "gana PLACSP" cuando PLACSP es más rico, pero no
 * hace falta mantener una lista de fuentes buenas y malas, y si algún día una
 * fuente regional publica mejores datos que PLACSP, gana ella.
 *
 * CORROBORACIÓN: MÁS EXIGENTE QUE EL FILTRO DE IMPORTACIÓN
 * --------------------------------------------------------
 * ExistingContractIndex empareja por importe + fecha + parecido del objeto. Eso
 * basta para NO insertar una fila (equivocarse cuesta una fila de más o de
 * menos), pero no para BORRAR una que ya está. El primer informe real sobre
 * P2807900B lo dejó claro: emparejó dos contratos distintos de la Banda
 * Sinfónica Municipal —"colaboración en la categoría de clarinete bajo" y
 * "colaboración en la categoría de percusión"— porque el objeto es una
 * plantilla casi idéntica, las fechas caían a 32 días y 1.404,96 × 1,21 daba
 * exactamente los 1.700,00 del otro. Adjudicatarios y expedientes distintos.
 *
 * Por eso aquí se exige además CORROBORAR con un identificador administrativo:
 * mismo número de expediente O mismo NIF de adjudicatario. Medido sobre las
 * 1.337 coincidencias reales del CSV de Madrid de 2026 contra PLACSP: el 98,8%
 * comparte expediente, el 90,7% comparte NIF de adjudicatario y el 99,3%
 * comparte al menos uno. O sea, la corroboración descarta el falso positivo sin
 * perder prácticamente ningún duplicado verdadero.
 *
 * NUNCA SE PIERDE INFORMACIÓN
 * ---------------------------
 *  - Los campos que el superviviente tiene a NULL y el descartado sí trae se
 *    trasladan antes de borrar.
 *  - `contratos_historial` tiene FK con ON DELETE CASCADE hacia `contratos`:
 *    borrar sin más se llevaría por delante el historial del descartado. Aquí
 *    se REASIGNA al superviviente antes de borrar (cada fila del historial ya
 *    guarda su propio placsp_id, así que la procedencia sigue siendo legible) y
 *    además se añade una fila nueva con el volcado íntegro del descartado. Así
 *    no quedan filas huérfanas ni se pierde el registro borrado.
 *
 * LIMITACIÓN CONOCIDA
 * -------------------
 * Los campos trasladados los podría volver a vaciar una reimportación COMPLETA
 * de la fuente del superviviente (el importador reescribe la fila entera). Los
 * syncs incrementales no revisitan contratos antiguos, así que en la práctica
 * aguantan; conviene tenerlo en cuenta antes de un `--full`.
 */
class MergeDuplicateContratos extends Command
{
    protected $signature = 'data:merge-duplicados
        {--nif= : NIF del órgano de contratación sobre el que actuar (ej: P2807900B)}
        {--fuente= : Slug de la fuente cuyos contratos se comparan con el resto}
        {--max-grupo=2 : Tamaño máximo de grupo a fusionar. 2 = sólo parejas}
        {--limit=0 : Máximo de grupos a procesar (0 = todos)}
        {--force : Aplica los cambios. SIN esta opción sólo informa y no toca nada}';

    protected $description = 'Fusiona contratos duplicados entre fuentes distintas (mismo órgano, importe, fecha y objeto)';

    /**
     * Si una fuente cubre más órganos que esto, se exige acotar con --nif: el
     * comando construye un índice por órgano y no debe barrer media base.
     */
    private const MAX_ORGANOS_SIN_NIF = 1000;

    /**
     * Campos que identifican el origen del dato. Son los que deciden quién
     * sobrevive: es el principio de trazabilidad del proyecto.
     *
     * @var array<int, string>
     */
    private const CAMPOS_TRAZABILIDAD = [
        'cpv', 'nuts', 'url_placsp', 'url_xml', 'uuid_ted', 'id_plataforma', 'expediente',
    ];

    /**
     * Campos que se trasladan al superviviente si él los tiene a NULL. Sólo
     * columnas nullable de datos: nunca identidad (placsp_id, fuente_datos_id),
     * ni contabilidad interna (hash, version), ni banderas con default
     * (es_menor, es_clm, moneda), donde "false" es un valor legítimo y no un
     * hueco que rellenar.
     *
     * @var array<int, string>
     */
    private const CAMPOS_TRASLADABLES = [
        'expediente', 'numero_lote', 'url_placsp', 'url_xml', 'uuid_ted', 'id_plataforma',
        'adjudicatario_id', 'nif_adjudicatario', 'pais_adjudicatario', 'nombre_adjudicatario',
        'objeto', 'tipo_contrato', 'subtipo_contrato', 'procedimiento', 'urgencia', 'estado',
        'resultado_codigo', 'importe_licitacion', 'importe_licitacion_con_iva', 'importe_estimado',
        'importe_adjudicacion', 'importe_adjudicacion_con_iva', 'duracion', 'cpv', 'nuts',
        'lugar_ejecucion', 'ciudad_ejecucion', 'codigo_postal_ejecucion', 'num_ofertas',
        'criterios_adjudicacion', 'financiacion_ue', 'fecha_publicacion', 'fecha_limite',
        'hora_limite', 'fecha_adjudicacion', 'fecha_formalizacion',
    ];

    private int $examinados = 0;

    private int $gruposDetectados = 0;

    private int $gruposFusionados = 0;

    private int $contratosEliminados = 0;

    private int $camposTrasladados = 0;

    private int $historialMigrado = 0;

    /** @var array<string, int> */
    private array $saltados = [];

    /**
     * Ids que se borrarían, sólo en modo informe: al final se cuenta de una vez
     * cuánto historial habría que migrar, en lugar de una consulta por grupo.
     *
     * @var array<int, int>
     */
    private array $idsADescartar = [];

    public function handle(): int
    {
        $force = (bool) $this->option('force');
        $maxGrupo = max(2, (int) $this->option('max-grupo'));
        $limite = (int) $this->option('limit');

        $nif = $this->option('nif');
        $nif = is_string($nif) && $nif !== '' ? mb_strtoupper(trim($nif)) : null;

        $fuenteSlug = $this->option('fuente');
        $fuenteSlug = is_string($fuenteSlug) && $fuenteSlug !== '' ? trim($fuenteSlug) : null;

        if ($nif === null && $fuenteSlug === null) {
            $this->error('Hay que acotar el alcance: indica --nif=<NIF de órgano> y/o --fuente=<slug>.');
            $this->line('Este comando borra contratos: no se ejecuta sobre toda la base de golpe.');

            return self::FAILURE;
        }

        $fuenteId = null;
        if ($fuenteSlug !== null) {
            $fuenteId = FuenteDatos::where('slug', $fuenteSlug)->value('id');
            if ($fuenteId === null) {
                $this->error("Fuente de datos \"{$fuenteSlug}\" no encontrada.");

                return self::FAILURE;
            }
            $fuenteId = (int) $fuenteId;
        }

        $organos = $this->resolveOrganos($nif, $fuenteId);
        if ($organos === null) {
            return self::FAILURE;
        }

        if ($organos === []) {
            $this->warn('No hay contratos en el alcance indicado.');

            return self::SUCCESS;
        }

        $this->info($force
            ? 'Fusionando contratos duplicados entre fuentes...'
            : 'MODO INFORME (sin --force): no se modificará ni borrará nada.');
        $this->line('Órganos en el alcance: '.number_format(count($organos)));
        $this->line('Tamaño máximo de grupo: '.$maxGrupo);
        $this->newLine();

        foreach ($organos as $organo) {
            if ($limite > 0 && $this->gruposDetectados >= $limite) {
                break;
            }

            $this->procesarOrgano($organo, $fuenteId, $force, $maxGrupo, $limite);
        }

        $this->mostrarResumen($force);

        return self::SUCCESS;
    }

    /**
     * Lista de NIF de órgano a procesar.
     *
     * @return array<int, string>|null null = error de alcance
     */
    private function resolveOrganos(?string $nif, ?int $fuenteId): ?array
    {
        if ($nif !== null) {
            return [$nif];
        }

        $query = DB::table('contratos')
            ->whereNotNull('nif_organo')
            ->where('fuente_datos_id', $fuenteId)
            ->distinct();

        $total = (clone $query)->count('nif_organo');

        if ($total > self::MAX_ORGANOS_SIN_NIF) {
            $this->error("La fuente cubre {$total} órganos distintos (máximo sin --nif: ".self::MAX_ORGANOS_SIN_NIF.').');
            $this->line('Acota con --nif=<NIF> para procesarlos de uno en uno.');

            return null;
        }

        return $query->orderBy('nif_organo')->pluck('nif_organo')->all();
    }

    private function procesarOrgano(string $nifOrgano, ?int $fuenteId, bool $force, int $maxGrupo, int $limite): void
    {
        // Índice de TODO el órgano (sin excluir fuentes): aquí no importamos,
        // buscamos parejas ya existentes entre fuentes distintas.
        $index = ExistingContractIndex::forOrgano($nifOrgano, null);

        if ($index->size() === 0) {
            return;
        }

        /** @var array<int, true> $procesados */
        $procesados = [];

        $candidatos = Contrato::query()
            ->where('nif_organo', $nifOrgano)
            ->whereNotNull('fecha_publicacion')
            ->when($fuenteId !== null, fn ($q) => $q->where('fuente_datos_id', $fuenteId))
            ->orderBy('id');

        $candidatos->chunkById(1000, function ($chunk) use (
            $index, &$procesados, $force, $maxGrupo, $limite
        ): bool {
            foreach ($chunk as $contrato) {
                if ($limite > 0 && $this->gruposDetectados >= $limite) {
                    return false;
                }

                $this->examinados++;

                if (isset($procesados[$contrato->id])) {
                    continue;
                }

                $matches = $index->findMatches($this->toIndexRow($contrato), $contrato->id);
                if ($matches === []) {
                    continue;
                }

                // Sólo interesan las coincidencias de OTRA fuente: dos filas de
                // la misma fuente con el mismo importe y objeto pueden ser dos
                // contratos reales distintos (el registro municipal de Madrid
                // repite números de registro), y ahí no queremos meter mano.
                $otrasFuentes = array_values(array_filter(
                    $matches,
                    fn (array $m): bool => $m['fuente'] !== $contrato->fuente_datos_id
                        && ! isset($procesados[$m['id']])
                ));

                if ($otrasFuentes === []) {
                    continue;
                }

                $this->gruposDetectados++;

                $grupo = Contrato::query()
                    ->whereIn('id', array_merge([$contrato->id], array_column($otrasFuentes, 'id')))
                    ->orderBy('id')
                    ->get();

                foreach ($grupo as $miembro) {
                    $procesados[$miembro->id] = true;
                }

                if ($grupo->count() > $maxGrupo) {
                    $this->saltar('grupo mayor que --max-grupo', $grupo->count());

                    continue;
                }

                $this->fusionarGrupo($grupo->all(), $force);
            }

            return true;
        });
    }

    /**
     * @param  array<int, Contrato>  $grupo
     */
    private function fusionarGrupo(array $grupo, bool $force): void
    {
        $superviviente = $this->elegirSuperviviente($grupo);
        $descartados = array_values(array_filter($grupo, fn (Contrato $c): bool => $c->id !== $superviviente->id));

        if ($descartados === []) {
            $this->saltar('grupo sin descartados', 1);

            return;
        }

        // Antes de borrar nada, exigir un identificador administrativo común.
        foreach ($descartados as $descartado) {
            if (! $this->corrobora($superviviente, $descartado)) {
                $this->saltar('sin expediente ni NIF de adjudicatario en común', 1);

                return;
            }
        }

        $traslados = [];
        foreach ($descartados as $descartado) {
            $traslados = $this->camposATrasladar($superviviente, $descartado, $traslados);
        }

        if (! $force) {
            $this->gruposFusionados++;
            $this->contratosEliminados += count($descartados);
            $this->camposTrasladados += count($traslados);

            foreach ($descartados as $descartado) {
                $this->idsADescartar[] = $descartado->id;
            }

            if ($this->gruposFusionados <= 10) {
                $this->mostrarEjemplo($superviviente, $descartados, $traslados);
            }

            return;
        }

        DB::transaction(function () use ($superviviente, $descartados, $traslados): void {
            foreach ($descartados as $descartado) {
                // 1. El historial del descartado pasa al superviviente ANTES de
                //    borrar: la FK es ON DELETE CASCADE y si no, se perdería.
                $this->historialMigrado += DB::table('contratos_historial')
                    ->where('contrato_id', $descartado->id)
                    ->update(['contrato_id' => $superviviente->id]);

                // 2. El propio descartado queda guardado como versión histórica
                //    del superviviente, con su placsp_id original.
                DB::table('contratos_historial')->insert([
                    'contrato_id' => $superviviente->id,
                    'placsp_id' => $descartado->placsp_id,
                    'datos_json' => json_encode($descartado->toArray()),
                    'fecha_updated' => $descartado->fecha_updated ?? $descartado->updated_at,
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);

                $descartado->delete();
                $this->contratosEliminados++;
            }

            if ($traslados !== []) {
                // Se rellena por fill() y no por asignación directa para que el
                // versionado quede igual que el de ContratoImporter.
                $superviviente->fill(array_merge($traslados, [
                    'version' => (int) $superviviente->version + 1,
                    'fecha_updated' => Carbon::now(),
                ]))->save();

                $this->camposTrasladados += count($traslados);
            }
        });

        $this->gruposFusionados++;
    }

    /**
     * ¿Hay un identificador administrativo que confirme que son el mismo
     * contrato? Basta con uno: mismo expediente o mismo NIF de adjudicatario.
     */
    private function corrobora(Contrato $a, Contrato $b): bool
    {
        $expedienteA = $this->normalizarCodigo($a->expediente);
        $expedienteB = $this->normalizarCodigo($b->expediente);

        if ($expedienteA !== null && $expedienteA === $expedienteB) {
            return true;
        }

        $nifA = $this->normalizarCodigo($a->nif_adjudicatario);
        $nifB = $this->normalizarCodigo($b->nif_adjudicatario);

        return $nifA !== null && $nifA === $nifB;
    }

    /**
     * Deja sólo letras y dígitos en mayúsculas: "511/2025/41819" y
     * "511-2025-41819" son el mismo expediente escrito por dos fuentes.
     */
    private function normalizarCodigo(mixed $valor): ?string
    {
        if (! is_string($valor)) {
            return null;
        }

        $limpio = (string) preg_replace('/[^A-Z0-9]/', '', mb_strtoupper($valor));

        return $limpio === '' ? null : $limpio;
    }

    /**
     * @param  array<int, Contrato>  $grupo
     */
    private function elegirSuperviviente(array $grupo): Contrato
    {
        usort($grupo, function (Contrato $a, Contrato $b): int {
            return $this->puntuarTrazabilidad($b) <=> $this->puntuarTrazabilidad($a)
                ?: $this->puntuarCompletitud($b) <=> $this->puntuarCompletitud($a)
                ?: ($a->created_at?->getTimestamp() ?? 0) <=> ($b->created_at?->getTimestamp() ?? 0)
                ?: $a->id <=> $b->id;
        });

        return $grupo[0];
    }

    private function puntuarTrazabilidad(Contrato $contrato): int
    {
        return $this->contarRellenos($contrato, self::CAMPOS_TRAZABILIDAD);
    }

    private function puntuarCompletitud(Contrato $contrato): int
    {
        return $this->contarRellenos($contrato, self::CAMPOS_TRASLADABLES);
    }

    /**
     * @param  array<int, string>  $campos
     */
    private function contarRellenos(Contrato $contrato, array $campos): int
    {
        $total = 0;

        foreach ($campos as $campo) {
            $valor = $contrato->getAttribute($campo);
            if ($valor !== null && $valor !== '') {
                $total++;
            }
        }

        return $total;
    }

    /**
     * Campos que el superviviente tiene vacíos y el descartado sí trae.
     *
     * @param  array<string, mixed>  $acumulado
     * @return array<string, mixed>
     */
    private function camposATrasladar(Contrato $superviviente, Contrato $descartado, array $acumulado): array
    {
        foreach (self::CAMPOS_TRASLADABLES as $campo) {
            if (array_key_exists($campo, $acumulado)) {
                continue;
            }

            if ($superviviente->getAttribute($campo) !== null) {
                continue;
            }

            $valor = $descartado->getAttribute($campo);
            if ($valor === null || $valor === '') {
                continue;
            }

            $acumulado[$campo] = $valor;
        }

        return $acumulado;
    }

    /**
     * Fila con la forma que espera ExistingContractIndex.
     *
     * @return array<string, mixed>
     */
    private function toIndexRow(Contrato $contrato): array
    {
        return [
            'importe_adjudicacion' => $contrato->importe_adjudicacion,
            'importe_adjudicacion_con_iva' => $contrato->importe_adjudicacion_con_iva,
            'fecha_publicacion' => $this->fechaComoTexto($contrato->fecha_publicacion),
            'objeto' => $contrato->objeto,
        ];
    }

    /**
     * El cast de `fecha_publicacion` devuelve Carbon en ejecución, pero el
     * atributo también puede llegar como string (hidratado en crudo).
     */
    private function fechaComoTexto(mixed $fecha): ?string
    {
        if ($fecha instanceof \DateTimeInterface) {
            return $fecha->format('Y-m-d');
        }

        return is_string($fecha) && $fecha !== '' ? $fecha : null;
    }

    private function saltar(string $motivo, int $cuantos = 1): void
    {
        $this->saltados[$motivo] = ($this->saltados[$motivo] ?? 0) + $cuantos;
    }

    /**
     * @param  array<int, Contrato>  $descartados
     * @param  array<string, mixed>  $traslados
     */
    private function mostrarEjemplo(Contrato $superviviente, array $descartados, array $traslados): void
    {
        $ids = implode(', ', array_map(fn (Contrato $c): string => (string) $c->placsp_id, $descartados));

        $this->line(sprintf(
            '  CONSERVA %s (traz. %d) | BORRA %s | traslada: %s',
            $superviviente->placsp_id,
            $this->puntuarTrazabilidad($superviviente),
            $ids,
            $traslados === [] ? 'nada' : implode(', ', array_keys($traslados))
        ));
    }

    /**
     * En modo informe, el historial a migrar se cuenta de una tacada al final.
     */
    private function contarHistorialPendiente(): void
    {
        foreach (array_chunk($this->idsADescartar, 1000) as $lote) {
            $this->historialMigrado += DB::table('contratos_historial')
                ->whereIn('contrato_id', $lote)
                ->count();
        }
    }

    private function mostrarResumen(bool $force): void
    {
        $prefix = $force ? '' : '[INFORME] ';

        if (! $force) {
            $this->contarHistorialPendiente();
        }

        $this->newLine();
        $this->info("{$prefix}Resumen de fusión de duplicados:");
        $this->table(['Metrica', 'Valor'], [
            ['Contratos examinados', number_format($this->examinados)],
            ['Grupos detectados', number_format($this->gruposDetectados)],
            [$force ? 'Grupos fusionados' : 'Grupos fusionables', number_format($this->gruposFusionados)],
            [$force ? 'Contratos eliminados' : 'Contratos a eliminar', number_format($this->contratosEliminados)],
            [$force ? 'Campos trasladados' : 'Campos a trasladar', number_format($this->camposTrasladados)],
            [$force ? 'Filas de historial migradas' : 'Filas de historial a migrar', number_format($this->historialMigrado)],
        ]);

        foreach ($this->saltados as $motivo => $cuantos) {
            $this->warn("  Saltados por {$motivo}: ".number_format($cuantos));
        }

        if (! $force) {
            $this->newLine();
            $this->warn('Nada se ha modificado. Ejecuta con --force para aplicar.');

            return;
        }

        if ($this->contratosEliminados > 0) {
            $this->newLine();
            $this->warn('Ejecuta stats:recalculate (y anomalias:detectar) para refrescar totales.');
        }
    }
}
