<?php

declare(strict_types=1);

namespace App\Services\Regional;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Índice en memoria de los contratos que YA tenemos de un órgano, para detectar
 * por contenido los que una fuente regional volvería a insertar.
 *
 * POR QUÉ HACE FALTA
 * ------------------
 * La deduplicación normal del proyecto va por `placsp_id`, y eso sólo funciona
 * dentro de una misma fuente: cada origen fabrica su propio identificador
 * (`AYTMAD-2025015610` frente al id de PLACSP), así que dos registros del mismo
 * contrato jamás colisionan. Medido sobre el CSV de menores del Ayuntamiento de
 * Madrid de 2026: de 2.743 filas, 1.337 ya estaban en la base vía PLACSP.
 *
 * CRITERIO (calibrado con datos reales, no a ojo)
 * -----------------------------------------------
 * Se considera duplicado un contrato que, para el mismo NIF de órgano, coincide
 * en las tres cosas a la vez:
 *
 *   1. IMPORTE exacto al céntimo. Se prueban las dos lecturas del importe del
 *      candidato (con IVA y base imponible) contra las dos columnas de importe
 *      del existente, porque las fuentes no coinciden en cuál publican: el CSV
 *      municipal da 231.251,57 € (IVA incluido) y PLACSP guarda ese mismo
 *      231.251,57 en `importe_adjudicacion`.
 *   2. FECHA de publicación dentro de ±45 días. Las fuentes fechan distinto
 *      (inscripción en el registro municipal frente a publicación en PLACSP):
 *      medido, el desfase va de -41 a +45 días con mediana -2. Ampliar a ±90
 *      sólo aporta 6 coincidencias más sobre 1.337, así que ±45 ya satura.
 *   3. OBJETO parecido. Este filtro NO es cosmético: sin él, importe+fecha da
 *      1.806 coincidencias en 2026 frente a 1.337 con él. Las 469 de diferencia
 *      son contratos distintos que coinciden por azar en importes redondos muy
 *      frecuentes en menores (3.000, 3.630, 7.260, 10.890 €, todos múltiplos
 *      típicos con el 21% de IVA) dentro de la misma ventana. Ejemplo real
 *      descartado por este filtro: "SERVICIO DE ILUSTRACIÓN PARA LA REVISTA
 *      EME21" contra "TALLERES CULTURA FLAMENCA", ambos por 3.000 €.
 *
 * El umbral de solape es 0,5 sobre el coeficiente de solapamiento
 * (|A∩B| / min(|A|,|B|)), no Jaccard: los objetos de una misma contratación
 * aparecen truncados o abreviados de forma distinta en cada fuente, y dividir
 * por el conjunto más corto tolera esa asimetría. La curva medida en 2026 es
 * 0,3→1.421, 0,4→1.357, 0,5→1.337, 0,6→1.328, 0,8→1.312: se aplana a partir de
 * 0,5, así que ahí está el codo. Por debajo de 0,4 empiezan a colarse los falsos
 * positivos descritos arriba.
 */
class ExistingContractIndex
{
    /**
     * Ventana de tolerancia entre fechas de publicación de las dos fuentes.
     */
    public const WINDOW_DAYS = 45;

    /**
     * Solape mínimo de tokens del objeto para dar dos contratos por el mismo.
     */
    public const MIN_OVERLAP = 0.5;

    /**
     * Longitud mínima de token: descarta artículos y preposiciones ("de", "la",
     * "por") que inflarían el solape sin aportar información.
     */
    private const MIN_TOKEN_LENGTH = 3;

    /**
     * Registros existentes.
     *
     * @var array<int, array{id: int, fuente: int|null, ts: int, objeto: string}>
     */
    private array $records = [];

    /**
     * Importe redondeado a céntimos → posiciones en $records.
     *
     * @var array<string, array<int, int>>
     */
    private array $byImporte = [];

    /**
     * Memo de tokens por posición: sólo se tokeniza el objeto de los candidatos
     * que realmente se comparan, no los ~26.000 del índice.
     *
     * @var array<int, array<int, string>>
     */
    private array $tokenCache = [];

    private function __construct() {}

    /**
     * Carga los contratos existentes de un órgano, excluyendo los de la propia
     * fuente (si no, una reimportación vería sus propias filas como duplicadas).
     *
     * Sólo entran al índice los que tienen importe y fecha: sin ellos nunca
     * podrían casar con nada.
     */
    public static function forOrgano(string $nifOrgano, ?int $excludeFuenteDatosId): self
    {
        $index = new self;

        DB::table('contratos')
            ->select(
                'id',
                'fuente_datos_id',
                'importe_adjudicacion',
                'importe_adjudicacion_con_iva',
                'fecha_publicacion',
                'objeto'
            )
            ->where('nif_organo', $nifOrgano)
            ->whereNotNull('fecha_publicacion')
            ->when($excludeFuenteDatosId !== null, function ($query) use ($excludeFuenteDatosId) {
                $query->where(function ($q) use ($excludeFuenteDatosId) {
                    $q->whereNull('fuente_datos_id')->orWhere('fuente_datos_id', '<>', $excludeFuenteDatosId);
                });
            })
            ->orderBy('id')
            ->chunk(5000, function ($rows) use ($index): void {
                foreach ($rows as $row) {
                    $index->add($row);
                }
            });

        return $index;
    }

    /**
     * Construye un índice vacío (fuente nueva o sin nada previo del órgano).
     */
    public static function empty(): self
    {
        return new self;
    }

    public function size(): int
    {
        return count($this->records);
    }

    /**
     * ¿Este contrato ya está en la base por otra fuente?
     *
     * Corta en la primera coincidencia: es la vía rápida que usan los comandos
     * de importación, que sólo necesitan saber si insertar o no.
     *
     * @param  array<string, mixed>  $data  Fila ya mapeada por el parser regional.
     */
    public function isDuplicate(array $data): bool
    {
        foreach ($this->walkMatches($data) as $_) {
            return true;
        }

        return false;
    }

    /**
     * Todas las coincidencias, para quien necesita el contrato con el que casa
     * y no sólo saber que existe (p. ej. data:merge-duplicados, que tiene que
     * decidir cuál de los dos sobrevive).
     *
     * @param  array<string, mixed>  $data
     * @param  int|null  $excludeId  Contrato a ignorar (normalmente, él mismo).
     * @return array<int, array{id: int, fuente: int|null}>
     */
    public function findMatches(array $data, ?int $excludeId = null): array
    {
        $matches = [];

        foreach ($this->walkMatches($data) as $record) {
            if ($excludeId !== null && $record['id'] === $excludeId) {
                continue;
            }

            $matches[$record['id']] = ['id' => $record['id'], 'fuente' => $record['fuente']];
        }

        return array_values($matches);
    }

    /**
     * Recorre las coincidencias de una fila contra el índice. Generador para que
     * isDuplicate() pueda cortar en la primera sin recorrer el resto.
     *
     * @param  array<string, mixed>  $data
     * @return \Generator<int, array{id: int, fuente: int|null, ts: int, objeto: string}>
     */
    private function walkMatches(array $data): \Generator
    {
        if ($this->records === []) {
            return;
        }

        $fecha = $data['fecha_publicacion'] ?? null;
        $objeto = $data['objeto'] ?? null;

        if (! is_string($fecha) || ! is_string($objeto)) {
            return;
        }

        $ts = strtotime($fecha);
        if ($ts === false) {
            return;
        }

        $tokens = $this->tokenize($objeto);
        if ($tokens === []) {
            return;
        }

        $window = self::WINDOW_DAYS * 86400;

        // Las dos lecturas del importe del candidato. Se prueban ambas porque no
        // hay forma fiable de saber si la otra fuente publicó base imponible o
        // total: en Madrid, PLACSP guarda el total en `importe_adjudicacion`.
        $importes = array_filter([
            $data['importe_adjudicacion_con_iva'] ?? null,
            $data['importe_adjudicacion'] ?? null,
        ], static fn ($v): bool => is_numeric($v) && (float) $v > 0);

        $vistos = [];

        foreach ($importes as $importe) {
            $key = $this->importeKey((float) $importe);

            foreach ($this->byImporte[$key] ?? [] as $position) {
                if (isset($vistos[$position])) {
                    continue;
                }
                $vistos[$position] = true;

                if (abs($this->records[$position]['ts'] - $ts) > $window) {
                    continue;
                }

                if ($this->overlap($tokens, $this->tokensAt($position)) >= self::MIN_OVERLAP) {
                    yield $this->records[$position];
                }
            }
        }
    }

    private function add(object $row): void
    {
        $ts = strtotime((string) $row->fecha_publicacion);
        if ($ts === false) {
            return;
        }

        $importes = array_filter(
            [$row->importe_adjudicacion, $row->importe_adjudicacion_con_iva],
            static fn ($v): bool => $v !== null && (float) $v > 0
        );

        if ($importes === []) {
            return;
        }

        $position = count($this->records);
        $this->records[$position] = [
            'id' => (int) $row->id,
            'fuente' => $row->fuente_datos_id !== null ? (int) $row->fuente_datos_id : null,
            'ts' => $ts,
            'objeto' => (string) ($row->objeto ?? ''),
        ];

        foreach (array_unique(array_map(fn ($v): string => $this->importeKey((float) $v), $importes)) as $key) {
            $this->byImporte[$key][] = $position;
        }
    }

    /**
     * Clave de importe: string con dos decimales, para que 1000 y 1000.00
     * caigan en el mismo cubo sin depender de la comparación de floats.
     */
    private function importeKey(float $importe): string
    {
        return number_format($importe, 2, '.', '');
    }

    /**
     * @return array<int, string>
     */
    private function tokensAt(int $position): array
    {
        return $this->tokenCache[$position] ??= $this->tokenize($this->records[$position]['objeto']);
    }

    /**
     * Normaliza y trocea el objeto: sin acentos, en mayúsculas, sin puntuación
     * y sin palabras cortas. Mismo criterio que App\Actions\NormalizeName (que
     * normaliza nombres de empresa), aquí sin las formas societarias.
     *
     * @return array<int, string>
     */
    private function tokenize(string $objeto): array
    {
        $normalized = mb_strtoupper(Str::ascii($objeto));
        $normalized = (string) preg_replace('/[^A-Z0-9\s]/', ' ', $normalized);
        $normalized = trim((string) preg_replace('/\s+/', ' ', $normalized));

        if ($normalized === '') {
            return [];
        }

        return array_values(array_unique(array_filter(
            explode(' ', $normalized),
            static fn (string $token): bool => mb_strlen($token) >= self::MIN_TOKEN_LENGTH
        )));
    }

    /**
     * Coeficiente de solapamiento: |A∩B| / min(|A|,|B|). Tolera que una fuente
     * publique el objeto abreviado y la otra completo.
     *
     * @param  array<int, string>  $a
     * @param  array<int, string>  $b
     */
    private function overlap(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }

        return count(array_intersect($a, $b)) / min(count($a), count($b));
    }
}
