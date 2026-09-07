<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Red de relaciones de una entidad: con quién contrata y con quién comparte esas
 * contrapartes.
 *
 * El grafo es ego-céntrico y de dos niveles. Para una empresa: los organismos que le
 * adjudican (nivel 1) y las otras empresas que también contratan con esos organismos
 * (nivel 2) — es decir, sus competidores donde compite. Para un organismo, lo simétrico:
 * sus proveedores y los otros organismos que comparten esos proveedores.
 *
 * El nivel 2 es la parte cara: agrupar por (contraparte, entidad) sobre todos los
 * contratos de organismos grandes recorre millones de filas —447 ms en un caso medio y
 * más en los extremos—. Se acota a una ventana de tres años, que además es lo que hace
 * útil el grafo: retrata relaciones vivas, no las de hace una década. Con la ventana, el
 * peor caso medido baja a ~1 s en frío.
 */
class RedRelacionesBuilder
{
    private const TTL = 86400;

    /** Contrapartes directas del nodo central. */
    private const LIMITE_NIVEL_1 = 10;

    /** Cuántas contrapartes de segundo nivel se traen por cada nodo del primero. */
    private const LIMITE_POR_NODO = 5;

    /** Años que abarca la red, contando el de referencia. */
    private const VENTANA_ANIOS = 3;

    /**
     * @return array{nodes: list<array<string, mixed>>, links: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function paraAdjudicatario(int $adjudicatarioId, int $anioReferencia): array
    {
        return Cache::remember(
            "red:adjudicatario:{$adjudicatarioId}:{$anioReferencia}",
            self::TTL,
            fn () => $this->construir('adjudicatario_id', $adjudicatarioId, 'organismo_id', $anioReferencia),
        );
    }

    /**
     * @return array{nodes: list<array<string, mixed>>, links: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function paraOrganismo(int $organismoId, int $anioReferencia): array
    {
        return Cache::remember(
            "red:organismo:{$organismoId}:{$anioReferencia}",
            self::TTL,
            fn () => $this->construir('organismo_id', $organismoId, 'adjudicatario_id', $anioReferencia),
        );
    }

    /**
     * @param  string  $columnaCentro  columna de la entidad central
     * @param  string  $columnaOtro  columna de la contraparte
     * @return array{nodes: list<array<string, mixed>>, links: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    private function construir(string $columnaCentro, int $centroId, string $columnaOtro, int $anioReferencia): array
    {
        $desde = sprintf('%04d-01-01', $anioReferencia - (self::VENTANA_ANIOS - 1));
        $hasta = sprintf('%04d-01-01', $anioReferencia + 1);

        $tablaCentro = $columnaCentro === 'organismo_id' ? 'organismos' : 'adjudicatarios';
        $tablaOtro = $columnaOtro === 'organismo_id' ? 'organismos' : 'adjudicatarios';
        $tipoCentro = $columnaCentro === 'organismo_id' ? 'organismo' : 'adjudicatario';
        $tipoOtro = $columnaOtro === 'organismo_id' ? 'organismo' : 'adjudicatario';

        // Nivel 1: contrapartes directas de la entidad en la ventana.
        $directas = DB::table('contratos')
            ->where($columnaCentro, $centroId)
            ->whereNotNull($columnaOtro)
            ->where('fecha_publicacion', '>=', $desde)
            ->where('fecha_publicacion', '<', $hasta)
            ->groupBy($columnaOtro)
            ->selectRaw("{$columnaOtro} as otro_id")
            ->selectRaw('COUNT(*) as num_contratos')
            ->selectRaw('COALESCE(SUM(importe_adjudicacion), 0) as total_importe')
            ->orderByDesc('total_importe')
            ->limit(self::LIMITE_NIVEL_1)
            ->get();

        if ($directas->isEmpty()) {
            return [
                'nodes' => [],
                'links' => [],
                'meta' => $this->meta($anioReferencia, $desde, 0, 0, $tipoCentro),
            ];
        }

        $idsNivel1 = $directas->pluck('otro_id')->all();

        // Nivel 2: quién más contrata con esas mismas contrapartes.
        $compartidas = DB::table('contratos')
            ->whereIn($columnaOtro, $idsNivel1)
            ->whereNotNull($columnaCentro)
            ->where($columnaCentro, '!=', $centroId)
            ->where('fecha_publicacion', '>=', $desde)
            ->where('fecha_publicacion', '<', $hasta)
            ->groupBy($columnaOtro, $columnaCentro)
            ->selectRaw("{$columnaOtro} as otro_id")
            ->selectRaw("{$columnaCentro} as par_id")
            ->selectRaw('COUNT(*) as num_contratos')
            ->selectRaw('COALESCE(SUM(importe_adjudicacion), 0) as total_importe')
            ->orderByDesc('total_importe')
            ->get()
            ->groupBy('otro_id')
            ->map(fn ($filas) => $filas->take(self::LIMITE_POR_NODO));

        $nombresOtro = $this->nombres($tablaOtro, $idsNivel1);
        $idsNivel2 = $compartidas->flatten(1)->pluck('par_id')->unique()->values()->all();
        $nombresPar = $this->nombres($tablaCentro, $idsNivel2);
        $centro = $this->nombres($tablaCentro, [$centroId])[$centroId] ?? ['nombre' => '—', 'nif' => ''];

        $nodes = [[
            'id' => 'centro',
            'label' => $centro['nombre'],
            'nif' => $centro['nif'],
            'tipo' => $tipoCentro,
            'nivel' => 0,
            'total_importe' => (float) $directas->sum('total_importe'),
        ]];
        $links = [];

        foreach ($directas as $fila) {
            $clave = 'n1_'.$fila->otro_id;
            $datos = $nombresOtro[$fila->otro_id] ?? ['nombre' => '—', 'nif' => ''];

            $nodes[] = [
                'id' => $clave,
                'label' => $datos['nombre'],
                'nif' => $datos['nif'],
                'tipo' => $tipoOtro,
                'nivel' => 1,
                'total_importe' => (float) $fila->total_importe,
            ];

            $links[] = [
                'source' => 'centro',
                'target' => $clave,
                'num_contratos' => (int) $fila->num_contratos,
                'total_importe' => (float) $fila->total_importe,
                'directo' => true,
            ];
        }

        $vistos = [];

        foreach ($compartidas as $otroId => $filas) {
            foreach ($filas as $fila) {
                $clave = 'n2_'.$fila->par_id;
                $datos = $nombresPar[$fila->par_id] ?? ['nombre' => '—', 'nif' => ''];

                if (! isset($vistos[$clave])) {
                    $vistos[$clave] = true;
                    $nodes[] = [
                        'id' => $clave,
                        'label' => $datos['nombre'],
                        'nif' => $datos['nif'],
                        'tipo' => $tipoCentro,
                        'nivel' => 2,
                        'total_importe' => 0.0,
                    ];
                }

                $links[] = [
                    'source' => 'n1_'.$otroId,
                    'target' => $clave,
                    'num_contratos' => (int) $fila->num_contratos,
                    'total_importe' => (float) $fila->total_importe,
                    'directo' => false,
                ];
            }
        }

        // El tamaño de los nodos de nivel 2 refleja lo que mueven en esta red, no su
        // volumen global: aquí interesa el peso dentro del entorno de la entidad.
        $importePorNodo = [];
        foreach ($links as $link) {
            if (! $link['directo']) {
                $importePorNodo[$link['target']] = ($importePorNodo[$link['target']] ?? 0) + $link['total_importe'];
            }
        }
        foreach ($nodes as $i => $nodo) {
            if ($nodo['nivel'] === 2) {
                $nodes[$i]['total_importe'] = (float) ($importePorNodo[$nodo['id']] ?? 0);
            }
        }

        return [
            'nodes' => $nodes,
            'links' => $links,
            'meta' => $this->meta($anioReferencia, $desde, $directas->count(), count($vistos), $tipoCentro),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function meta(int $anioReferencia, string $desde, int $directas, int $compartidas, string $tipoCentro): array
    {
        return [
            'anio_hasta' => $anioReferencia,
            'anio_desde' => (int) substr($desde, 0, 4),
            'directas' => $directas,
            'compartidas' => $compartidas,
            'tipo_centro' => $tipoCentro,
        ];
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, array{nombre: string, nif: string}>
     */
    private function nombres(string $tabla, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table($tabla)
            ->whereIn('id', $ids)
            ->get(['id', 'nombre', 'nif'])
            ->mapWithKeys(fn ($fila) => [
                (int) $fila->id => ['nombre' => (string) $fila->nombre, 'nif' => (string) $fila->nif],
            ])
            ->all();
    }
}
