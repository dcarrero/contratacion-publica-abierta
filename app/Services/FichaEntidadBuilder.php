<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Contrato;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Bloques de la ficha de una entidad (empresa u organismo) acotados a un año concreto.
 *
 * La serie temporal —KPIs comparados, evolución, histórico— la construye
 * {@see SerieTemporalBuilder}; aquí viven los agregados que no son temporales: quién
 * contrata con quién, en qué tipos de contrato y con cuánta concurrencia, dentro del año
 * que la ficha está mostrando.
 *
 * Todas las consultas se acotan con `scopeYear`, que es un rango de fechas sargable: con
 * el índice (entidad, fecha_publicacion) el año recorta el escaneo en vez de ampliarlo.
 */
class FichaEntidadBuilder
{
    private const TTL = 3600;

    private const TOP_LIMITE = 10;

    /**
     * Ficha de un adjudicatario: con qué organismos contrata.
     *
     * @return array{anio: int|null, kpis: array{contratos: int, importe: float, ticket_medio: float|null, contrapartes: int, contrato_mayor: float|null}, top: Collection<int, Contrato>, distribucion_tipo: Collection<int, Contrato>}
     */
    public function adjudicatario(int $adjudicatarioId, ?int $anio = null): array
    {
        return Cache::remember(
            "ficha:adjudicatario:{$adjudicatarioId}:".($anio ?? 'all'),
            self::TTL,
            fn () => $this->construir(
                fn () => Contrato::query()->forAdjudicatario($adjudicatarioId),
                $anio,
                columnaTop: 'organismo_id',
                relacionTop: 'organismo:id,nombre,nif',
            ),
        );
    }

    /**
     * Ficha de un organismo: a quién adjudica. Incluye los indicadores de concurrencia
     * (contratos con una sola oferta y concentración de proveedores), que sólo tienen
     * sentido del lado del comprador.
     *
     * @return array{anio: int|null, kpis: array{contratos: int, importe: float, ticket_medio: float|null, contrapartes: int, contrato_mayor: float|null, pct_menores: float, pct_sin_concurrencia: float|null, contratos_con_num_ofertas: int, concentracion_hhi: int|null, concentracion_label: string|null}, top: Collection<int, Contrato>, distribucion_tipo: Collection<int, Contrato>}
     */
    public function organismo(int $organismoId, ?int $anio = null): array
    {
        return Cache::remember(
            "ficha:organismo:{$organismoId}:".($anio ?? 'all'),
            self::TTL,
            function () use ($organismoId, $anio) {
                $base = fn () => Contrato::query()->forOrganismo($organismoId);

                $ficha = $this->construir(
                    $base,
                    $anio,
                    columnaTop: 'adjudicatario_id',
                    relacionTop: 'adjudicatario:id,nombre,nif',
                );

                $ficha['kpis'] = [
                    ...$ficha['kpis'],
                    ...$this->indicadoresConcurrencia($base, $anio, $ficha['kpis']['contratos'], $ficha['kpis']['importe']),
                ];

                return $ficha;
            },
        );
    }

    /**
     * Ficha de un territorio (CCAA o provincia) por prefijo NUTS: qué organismos contratan
     * ahí y en qué tipos de contrato, dentro del año mostrado.
     *
     * @return array{anio: int|null, kpis: array{contratos: int, importe: float, ticket_medio: float|null, contrapartes: int, contrato_mayor: float|null}, top: Collection<int, Contrato>, distribucion_tipo: Collection<int, Contrato>}
     */
    public function territorio(string $nutsPrefix, ?int $anio = null): array
    {
        return Cache::remember(
            "ficha:nuts:{$nutsPrefix}:".($anio ?? 'all'),
            // Los territorios agregan cientos de miles de filas: se cachean un día, como
            // las series, en vez de la hora que basta para una entidad concreta.
            86400,
            fn () => $this->construir(
                fn () => Contrato::query()->ccaa($nutsPrefix),
                $anio,
                columnaTop: 'organismo_id',
                relacionTop: 'organismo:id,nombre,nif',
            ),
        );
    }

    /** Invalida la ficha cacheada de una entidad en todos sus años conocidos. */
    public function olvidar(string $tipo, int $id, array $anios = []): void
    {
        Cache::forget("ficha:{$tipo}:{$id}:all");

        foreach ($anios as $anio) {
            Cache::forget("ficha:{$tipo}:{$id}:{$anio}");
        }
    }

    /**
     * @param  callable(): Builder<Contrato>  $base
     * @return array{anio: int|null, kpis: array{contratos: int, importe: float, ticket_medio: float|null, contrapartes: int, contrato_mayor: float|null}, top: Collection<int, Contrato>, distribucion_tipo: Collection<int, Contrato>}
     */
    private function construir(callable $base, ?int $anio, string $columnaTop, string $relacionTop): array
    {
        $delAnio = fn (): Builder => $anio === null ? $base() : $base()->year($anio);

        $agg = $delAnio()
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('COALESCE(SUM(importe_adjudicacion), 0) as importe_total')
            ->selectRaw("COUNT(DISTINCT {$columnaTop}) as contrapartes")
            ->selectRaw('MAX(importe_adjudicacion) as contrato_mayor')
            ->toBase()
            ->first();

        $contratos = (int) ($agg->total ?? 0);
        $importe = (float) ($agg->importe_total ?? 0);

        return [
            'anio' => $anio,
            'kpis' => [
                'contratos' => $contratos,
                'importe' => $importe,
                // Sobre el total de contratos, no sólo los que declaran importe: un ticket
                // medio calculado sólo sobre los que lo declaran sale inflado.
                'ticket_medio' => $contratos > 0 ? $importe / $contratos : null,
                'contrapartes' => (int) ($agg->contrapartes ?? 0),
                'contrato_mayor' => $agg->contrato_mayor === null ? null : (float) $agg->contrato_mayor,
            ],
            'top' => $delAnio()
                ->select($columnaTop)
                ->selectRaw('COUNT(*) as num_contratos')
                ->selectRaw('COALESCE(SUM(importe_adjudicacion), 0) as total_importe')
                ->whereNotNull($columnaTop)
                ->groupBy($columnaTop)
                ->orderByDesc('total_importe')
                ->limit(self::TOP_LIMITE)
                ->with($relacionTop)
                ->get(),
            'distribucion_tipo' => $delAnio()
                ->select('tipo_contrato')
                ->selectRaw('COUNT(*) as num_contratos')
                ->selectRaw('COALESCE(SUM(importe_adjudicacion), 0) as total_importe')
                ->whereNotNull('tipo_contrato')
                ->groupBy('tipo_contrato')
                ->orderByDesc('total_importe')
                ->get(),
        ];
    }

    /**
     * @param  callable(): Builder<Contrato>  $base
     * @return array{pct_menores: float, pct_sin_concurrencia: float|null, contratos_con_num_ofertas: int, concentracion_hhi: int|null, concentracion_label: string|null}
     */
    private function indicadoresConcurrencia(callable $base, ?int $anio, int $contratos, float $importe): array
    {
        $delAnio = fn (): Builder => $anio === null ? $base() : $base()->year($anio);

        $menores = (int) $delAnio()->menores()->toBase()->count();

        // % sin concurrencia (una sola oferta) sobre los contratos que declaran num_ofertas.
        $ofertas = $delAnio()
            ->whereNotNull('num_ofertas')
            ->selectRaw('COUNT(*) as con_dato')
            ->selectRaw('SUM(CASE WHEN num_ofertas = 1 THEN 1 ELSE 0 END) as una_oferta')
            ->toBase()
            ->first();

        $conDato = (int) ($ofertas->con_dato ?? 0);

        // Índice Herfindahl (HHI) de concentración de proveedores por importe: 0–10.000.
        // <1.500 competencia, 1.500–2.500 moderada, >2.500 alta dependencia de pocos.
        $hhi = null;
        if ($importe > 0) {
            $sub = $delAnio()
                ->whereNotNull('adjudicatario_id')
                ->groupBy('adjudicatario_id')
                ->selectRaw('SUM(importe_adjudicacion) as imp');

            $sumSq = (float) DB::query()->fromSub($sub, 't')
                ->selectRaw('COALESCE(SUM(imp * imp), 0) as s')
                ->value('s');

            $hhi = (int) round($sumSq / ($importe ** 2) * 10000);
        }

        return [
            'pct_menores' => $contratos > 0 ? round($menores / $contratos * 100, 1) : 0.0,
            'pct_sin_concurrencia' => $conDato > 0 ? round(((int) $ofertas->una_oferta) / $conDato * 100, 1) : null,
            'contratos_con_num_ofertas' => $conDato,
            'concentracion_hhi' => $hhi,
            'concentracion_label' => $hhi === null ? null : ($hhi > 2500 ? 'Alta' : ($hhi >= 1500 ? 'Moderada' : 'Baja')),
        ];
    }
}
