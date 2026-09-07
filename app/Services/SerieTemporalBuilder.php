<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Contrato;
use App\Support\SerieTemporal;
use App\Support\SqlDialect;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Construye la serie mensual de contratación de una entidad con UNA sola consulta
 * agrupada por año-mes, de la que salen también los contratos sin fecha.
 *
 * Coste medido en frío sobre la BD real (8,43M contratos, PostgreSQL 17):
 * - empresa media (1.162 contratos): ~3 ms — índice `contratos_adjudicatario_fecha_index`
 * - organismo mayor (256.598 contratos): ~480 ms — índice `contratos_organismo_fecha_index`
 * - CCAA `ES30` (500K filas por prefijo NUTS): ~5 s
 * Cacheado, cualquiera de los tres: ~2 ms.
 *
 * Por eso empresas y organismos se resuelven al vuelo con caché de una hora, mientras que
 * los territorios llevan caché diaria: su coste es un orden de magnitud mayor y la Fase 3
 * del plan 037 los servirá desde los JSON precomputados de `stats:recalculate`.
 */
class SerieTemporalBuilder
{
    /** Caché de fichas de entidad: se refresca sola tras cada sync diario. */
    private const TTL_ENTIDAD = 3600;

    /** Caché de territorios y del agregado nacional: consultas mucho más caras. */
    private const TTL_TERRITORIO = 86400;

    public function paraAdjudicatario(int $adjudicatarioId): SerieTemporal
    {
        return $this->cacheada(
            "serie:adjudicatario:{$adjudicatarioId}",
            self::TTL_ENTIDAD,
            fn () => Contrato::query()->forAdjudicatario($adjudicatarioId),
        );
    }

    public function paraOrganismo(int $organismoId): SerieTemporal
    {
        return $this->cacheada(
            "serie:organismo:{$organismoId}",
            self::TTL_ENTIDAD,
            fn () => Contrato::query()->forOrganismo($organismoId),
        );
    }

    /**
     * Serie de un territorio por prefijo NUTS ('ES42' para una CCAA, 'ES424' para una
     * provincia).
     *
     * Si `stats:recalculate` ya dejó la serie precomputada para esa comunidad, se sirve de
     * ahí: recorrer medio millón de filas por prefijo NUTS cuesta unos 5 segundos, y el
     * dato es el mismo. En su defecto cae a la consulta directa con `scopeCcaa`, que
     * traduce el prefijo a un rango sargable.
     */
    public function paraNuts(string $nutsPrefix): SerieTemporal
    {
        $precomputada = $this->seriePrecomputada($nutsPrefix);

        if ($precomputada !== null) {
            return $precomputada;
        }

        return $this->cacheada(
            "serie:nuts:{$nutsPrefix}",
            self::TTL_TERRITORIO,
            fn () => Contrato::query()->ccaa($nutsPrefix),
        );
    }

    /** Serie de `mapa-stats/admin-{nuts}.json`, si el recálculo de stats la dejó escrita. */
    private function seriePrecomputada(string $nutsPrefix): ?SerieTemporal
    {
        return $this->serieDeFichero("mapa-stats/admin-{$nutsPrefix}.json");
    }

    /** Serie guardada en un JSON de `mapa-stats`, o null si no está o no la trae. */
    private function serieDeFichero(string $fichero): ?SerieTemporal
    {
        if (! Storage::exists($fichero)) {
            return null;
        }

        $datos = json_decode((string) Storage::get($fichero), true);

        // Un JSON de una versión anterior no trae la serie: mejor recalcularla que mentir.
        if (! is_array($datos) || ! isset($datos['serie_mensual']) || ! is_array($datos['serie_mensual'])) {
            return null;
        }

        return new SerieTemporal($datos['serie_mensual'], (int) ($datos['serie_sin_fecha'] ?? 0));
    }

    /**
     * Serie del conjunto nacional (portada e histórico nacional). Igual que los
     * territorios, prefiere lo que dejó `stats:recalculate`: agrupar los 8,6M de contratos
     * por año-mes en cada visita no es viable.
     */
    public function nacional(): SerieTemporal
    {
        $precomputada = $this->serieDeFichero('mapa-stats/serie-nacional.json');

        if ($precomputada !== null) {
            return $precomputada;
        }

        return $this->cacheada(
            'serie:nacional',
            self::TTL_TERRITORIO,
            fn () => Contrato::query(),
        );
    }

    /**
     * Serie a partir de una consulta arbitraria, sin caché. Para usos puntuales (informes,
     * comandos); las rutas web deben ir por los métodos cacheados de arriba.
     *
     * @param  callable(): Builder<Contrato>  $query
     */
    public function paraQuery(callable $query): SerieTemporal
    {
        return $this->construir($query);
    }

    /**
     * @param  callable(): Builder<Contrato>  $query
     */
    private function cacheada(string $clave, int $ttl, callable $query): SerieTemporal
    {
        /** @var array{filas: list<array{anio: int, mes: int, num: int, importe: float}>, sin_fecha: int} $datos */
        $datos = Cache::remember($clave, $ttl, fn () => $this->consultar($query));

        return new SerieTemporal($datos['filas'], $datos['sin_fecha']);
    }

    /**
     * @param  callable(): Builder<Contrato>  $query
     */
    private function construir(callable $query): SerieTemporal
    {
        $datos = $this->consultar($query);

        return new SerieTemporal($datos['filas'], $datos['sin_fecha']);
    }

    /**
     * Se cachea el array plano y no el objeto: serializar un value object ata la caché a
     * la forma actual de la clase, y un cambio de firma dejaría entradas incompatibles.
     *
     * Los contratos sin `fecha_publicacion` salen de esta misma consulta —agrupan bajo un
     * periodo NULL en los tres drivers— en vez de un `COUNT` aparte: contarlos por
     * separado obligaba a recorrer dos veces el mismo medio millón de filas en los
     * territorios grandes.
     *
     * @param  callable(): Builder<Contrato>  $query
     * @return array{filas: list<array{anio: int, mes: int, num: int, importe: float}>, sin_fecha: int}
     */
    private function consultar(callable $query): array
    {
        $yearMonth = SqlDialect::yearMonth('fecha_publicacion');

        // toBase(): filas planas de stdClass en vez de modelos Eloquent hidratados. Sólo se
        // leen tres agregados, y una CCAA grande produciría cientos de modelos inútiles.
        $resultado = $query()
            ->toBase()
            ->selectRaw("{$yearMonth} as periodo")
            ->selectRaw('COUNT(*) as num')
            ->selectRaw('COALESCE(SUM(importe_adjudicacion), 0) as importe')
            ->groupByRaw($yearMonth)
            ->orderByRaw($yearMonth)
            ->get();

        $filas = [];
        $sinFecha = 0;

        foreach ($resultado as $fila) {
            // 'YYYY-MM' en los tres drivers (ver SqlDialect::yearMonth); NULL cuando el
            // contrato no tiene fecha de publicación.
            [$anio, $mes] = array_pad(explode('-', (string) $fila->periodo, 2), 2, null);

            if ($anio === null || $mes === null || ! ctype_digit($anio) || ! ctype_digit($mes)) {
                $sinFecha += (int) $fila->num;

                continue;
            }

            $filas[] = [
                'anio' => (int) $anio,
                'mes' => (int) $mes,
                'num' => (int) $fila->num,
                'importe' => (float) $fila->importe,
            ];
        }

        return ['filas' => $filas, 'sin_fecha' => $sinFecha];
    }
}
