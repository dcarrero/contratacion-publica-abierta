<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Serie temporal mensual de contratación de una entidad (empresa, organismo, territorio
 * o el conjunto nacional). Se construye con UNA sola consulta que devuelve la serie
 * mensual completa (~200 filas para 18 años); todo lo demás —totales por año, comparativa
 * interanual, matriz año × mes— se deriva aquí en memoria, sin volver a la base de datos.
 *
 * Regla: ningún controlador debe agrupar por año/mes por su cuenta ni lanzar una consulta
 * por año. Ver `plans/037-vistas-anuales-historico.md`.
 */
final class SerieTemporal
{
    /** Abreviaturas de mes para etiquetar periodos ("ene–ago"). Índice 1..12. */
    public const MESES_CORTOS = [
        1 => 'ene', 2 => 'feb', 3 => 'mar', 4 => 'abr', 5 => 'may', 6 => 'jun',
        7 => 'jul', 8 => 'ago', 9 => 'sep', 10 => 'oct', 11 => 'nov', 12 => 'dic',
    ];

    /**
     * Serie indexada por año y mes: [anio][mes] => ['num' => int, 'importe' => float].
     *
     * @var array<int, array<int, array{num: int, importe: float}>>
     */
    private array $porAnioMes = [];

    /**
     * @param  list<array{anio: int, mes: int, num: int, importe: float}>  $filas
     *                                                                             Filas crudas de la consulta mensual. No hace falta que vengan ordenadas
     *                                                                             ni completas: los meses sin contratos simplemente no aparecen.
     * @param  int  $sinFecha  Contratos de la entidad sin `fecha_publicacion`, que quedan
     *                         fuera de toda serie temporal. Se expone para que la vista pueda
     *                         explicar por qué los totales no cuadran con el listado.
     */
    public function __construct(array $filas = [], public readonly int $sinFecha = 0)
    {
        foreach ($filas as $fila) {
            $anio = (int) $fila['anio'];
            $mes = (int) $fila['mes'];

            if ($mes < 1 || $mes > 12) {
                continue;
            }

            // Un mismo (año, mes) repetido se acumula en vez de sobrescribirse.
            $this->porAnioMes[$anio][$mes]['num'] = ($this->porAnioMes[$anio][$mes]['num'] ?? 0) + (int) $fila['num'];
            $this->porAnioMes[$anio][$mes]['importe'] = ($this->porAnioMes[$anio][$mes]['importe'] ?? 0.0) + (float) $fila['importe'];
        }

        ksort($this->porAnioMes);
        foreach ($this->porAnioMes as &$meses) {
            ksort($meses);
        }
        unset($meses);
    }

    public function estaVacia(): bool
    {
        return $this->porAnioMes === [];
    }

    /**
     * Años con al menos un contrato, ascendente.
     *
     * @return list<int>
     */
    public function aniosConDatos(): array
    {
        return array_keys($this->porAnioMes);
    }

    public function primerAnio(): ?int
    {
        $anios = $this->aniosConDatos();

        return $anios === [] ? null : $anios[0];
    }

    public function ultimoAnio(): ?int
    {
        $anios = $this->aniosConDatos();

        return $anios === [] ? null : $anios[count($anios) - 1];
    }

    public function tieneDatosEn(int $anio): bool
    {
        return isset($this->porAnioMes[$anio]);
    }

    /**
     * Año sobre el que centrar la ficha. Si la entidad no tiene actividad en el año en
     * curso —el caso mayoritario en empresas de cola larga— devuelve el último año con
     * datos, para que la vista muestre algo útil en vez de una ficha vacía.
     */
    public function anioReferencia(?int $anioActual = null): ?int
    {
        $anioActual ??= (int) date('Y');

        return $this->tieneDatosEn($anioActual) ? $anioActual : $this->ultimoAnio();
    }

    /**
     * ¿La entidad está inactiva en el año en curso? La vista lo usa para mostrar el aviso
     * de "sin actividad en {año}" y destacar el acceso al histórico.
     */
    public function inactivaEn(?int $anioActual = null): bool
    {
        $anioActual ??= (int) date('Y');

        return ! $this->estaVacia() && ! $this->tieneDatosEn($anioActual);
    }

    /**
     * Totales acumulados de toda la serie.
     *
     * @return array{num: int, importe: float}
     */
    public function totales(): array
    {
        $num = 0;
        $importe = 0.0;

        foreach ($this->porAnioMes as $meses) {
            foreach ($meses as $dato) {
                $num += $dato['num'];
                $importe += $dato['importe'];
            }
        }

        return ['num' => $num, 'importe' => $importe];
    }

    /**
     * Totales de un año concreto.
     *
     * @return array{num: int, importe: float}
     */
    public function totalAnio(int $anio): array
    {
        $num = 0;
        $importe = 0.0;

        foreach ($this->porAnioMes[$anio] ?? [] as $dato) {
            $num += $dato['num'];
            $importe += $dato['importe'];
        }

        return ['num' => $num, 'importe' => $importe];
    }

    /**
     * Totales por año, del más reciente al más antiguo, con variación interanual y peso
     * sobre el total histórico. Alimenta la tabla de la página de histórico.
     *
     * @return list<array{anio: int, num: int, importe: float, variacion_num: float|null, variacion_importe: float|null, pct_total_num: float, pct_total_importe: float}>
     */
    public function anios(): array
    {
        $totalHistorico = $this->totales();
        $filas = [];

        foreach ($this->aniosConDatos() as $anio) {
            $actual = $this->totalAnio($anio);
            $anterior = $this->tieneDatosEn($anio - 1) ? $this->totalAnio($anio - 1) : null;

            $filas[] = [
                'anio' => $anio,
                'num' => $actual['num'],
                'importe' => $actual['importe'],
                'variacion_num' => $anterior === null ? null : self::variacion($actual['num'], $anterior['num']),
                'variacion_importe' => $anterior === null ? null : self::variacion($actual['importe'], $anterior['importe']),
                'pct_total_num' => $totalHistorico['num'] > 0 ? round($actual['num'] / $totalHistorico['num'] * 100, 1) : 0.0,
                'pct_total_importe' => $totalHistorico['importe'] > 0 ? round($actual['importe'] / $totalHistorico['importe'] * 100, 1) : 0.0,
            ];
        }

        return array_reverse($filas);
    }

    /**
     * Los 12 meses de un año, incluidos los vacíos (num 0) para que el gráfico no se
     * deforme. `parcial` marca el mes en curso (dato incompleto) y `futuro` los meses que
     * aún no han ocurrido, que la vista debe dejar sin pintar.
     *
     * @return list<array{mes: int, etiqueta: string, num: int, importe: float, parcial: bool, futuro: bool}>
     */
    public function anio(int $anio, ?int $anioActual = null, ?int $mesActual = null): array
    {
        $anioActual ??= (int) date('Y');
        $mesActual ??= (int) date('n');

        $meses = [];

        for ($mes = 1; $mes <= 12; $mes++) {
            $dato = $this->porAnioMes[$anio][$mes] ?? null;

            $meses[] = [
                'mes' => $mes,
                'etiqueta' => self::MESES_CORTOS[$mes],
                'num' => $dato['num'] ?? 0,
                'importe' => $dato['importe'] ?? 0.0,
                'parcial' => $anio === $anioActual && $mes === $mesActual,
                'futuro' => $anio > $anioActual || ($anio === $anioActual && $mes > $mesActual),
            ];
        }

        return $meses;
    }

    /**
     * Varias series anuales alineadas por mes, para superponerlas en un mismo gráfico.
     *
     * @param  list<int>  $anios
     * @return array<int, list<array{mes: int, etiqueta: string, num: int, importe: float, parcial: bool, futuro: bool}>>
     */
    public function mesesDe(array $anios, ?int $anioActual = null, ?int $mesActual = null): array
    {
        $series = [];

        foreach ($anios as $anio) {
            $series[$anio] = $this->anio($anio, $anioActual, $mesActual);
        }

        return $series;
    }

    /**
     * Comparativa del año en curso contra el anterior **sobre el mismo tramo de meses**
     * (YTD): en septiembre compara ene–ago con ene–ago, no 9 meses contra 12. El mes en
     * curso se excluye por estar incompleto.
     *
     * En enero no hay ningún mes cerrado del año en curso, así que la comparativa útil es
     * la del año anterior completo contra el anterior a ése; se señala con `fallback`.
     *
     * @return array{anio: int, anio_anterior: int, hasta_mes: int, periodo: string, completo: bool, fallback: string|null, actual: array{num: int, importe: float}, anterior: array{num: int, importe: float}, variacion_num: float|null, variacion_importe: float|null, variacion_num_abs: int, variacion_importe_abs: float}
     */
    public function comparativaYtd(?int $anio = null, ?int $anioActual = null, ?int $mesActual = null): array
    {
        $anioActual ??= (int) date('Y');
        $mesActual ??= (int) date('n');
        $anio ??= $anioActual;

        $fallback = null;

        if ($anio === $anioActual) {
            // Solo meses cerrados del año en curso.
            $hastaMes = $mesActual - 1;

            if ($hastaMes < 1) {
                // Enero: sin meses cerrados. Se compara el año anterior completo.
                $anio = $anioActual - 1;
                $hastaMes = 12;
                $fallback = 'enero';
            }
        } else {
            // Año pasado: se compara completo.
            $hastaMes = 12;
        }

        $actual = $this->acumuladoHasta($anio, $hastaMes);
        $anterior = $this->acumuladoHasta($anio - 1, $hastaMes);

        return [
            'anio' => $anio,
            'anio_anterior' => $anio - 1,
            'hasta_mes' => $hastaMes,
            'periodo' => self::etiquetaPeriodo($hastaMes),
            'completo' => $hastaMes === 12,
            'fallback' => $fallback,
            'actual' => $actual,
            'anterior' => $anterior,
            'variacion_num' => self::variacion($actual['num'], $anterior['num']),
            'variacion_importe' => self::variacion($actual['importe'], $anterior['importe']),
            'variacion_num_abs' => $actual['num'] - $anterior['num'],
            'variacion_importe_abs' => $actual['importe'] - $anterior['importe'],
        ];
    }

    /**
     * Acumulado de un año desde enero hasta el mes indicado (incluido).
     *
     * @return array{num: int, importe: float}
     */
    public function acumuladoHasta(int $anio, int $hastaMes): array
    {
        $num = 0;
        $importe = 0.0;

        foreach ($this->porAnioMes[$anio] ?? [] as $mes => $dato) {
            if ($mes > $hastaMes) {
                break;
            }
            $num += $dato['num'];
            $importe += $dato['importe'];
        }

        return ['num' => $num, 'importe' => $importe];
    }

    /**
     * Mes con más importe adjudicado de toda la serie.
     *
     * @return array{anio: int, mes: int, etiqueta: string, num: int, importe: float}|null
     */
    public function mejorMes(): ?array
    {
        $mejor = null;

        foreach ($this->porAnioMes as $anio => $meses) {
            foreach ($meses as $mes => $dato) {
                if ($mejor === null || $dato['importe'] > $mejor['importe']) {
                    $mejor = [
                        'anio' => $anio,
                        'mes' => $mes,
                        'etiqueta' => self::MESES_CORTOS[$mes],
                        'num' => $dato['num'],
                        'importe' => $dato['importe'],
                    ];
                }
            }
        }

        return $mejor;
    }

    /**
     * Año con más importe adjudicado.
     *
     * @return array{anio: int, num: int, importe: float}|null
     */
    public function mejorAnio(): ?array
    {
        $mejor = null;

        foreach ($this->aniosConDatos() as $anio) {
            $total = $this->totalAnio($anio);
            if ($mejor === null || $total['importe'] > $mejor['importe']) {
                $mejor = ['anio' => $anio, ...$total];
            }
        }

        return $mejor;
    }

    /**
     * Último (año, mes) con contratos.
     *
     * @return array{anio: int, mes: int, etiqueta: string, num: int, importe: float}|null
     */
    public function ultimoMesConDatos(): ?array
    {
        $anio = $this->ultimoAnio();

        if ($anio === null) {
            return null;
        }

        $meses = $this->porAnioMes[$anio];
        $mes = array_key_last($meses);

        return [
            'anio' => $anio,
            'mes' => (int) $mes,
            'etiqueta' => self::MESES_CORTOS[$mes],
            'num' => $meses[$mes]['num'],
            'importe' => $meses[$mes]['importe'],
        ];
    }

    /**
     * Matriz año × mes para el heatmap del histórico: una fila por año (descendente) con
     * sus 12 meses y el máximo de la matriz, que la vista usa para normalizar el color.
     *
     * @return array{max_num: int, max_importe: float, filas: list<array{anio: int, meses: list<array{mes: int, etiqueta: string, num: int, importe: float, parcial: bool, futuro: bool}>, total: array{num: int, importe: float}}>}
     */
    public function matrizAnioMes(?int $anioActual = null, ?int $mesActual = null): array
    {
        $filas = [];
        $maxNum = 0;
        $maxImporte = 0.0;

        foreach (array_reverse($this->aniosConDatos()) as $anio) {
            $meses = $this->anio($anio, $anioActual, $mesActual);

            foreach ($meses as $mes) {
                $maxNum = max($maxNum, $mes['num']);
                $maxImporte = max($maxImporte, $mes['importe']);
            }

            $filas[] = [
                'anio' => $anio,
                'meses' => $meses,
                'total' => $this->totalAnio($anio),
            ];
        }

        return ['max_num' => $maxNum, 'max_importe' => $maxImporte, 'filas' => $filas];
    }

    /**
     * Variación porcentual respecto a la base. `null` cuando la base es 0: un crecimiento
     * desde cero no es un porcentaje, y pintar "+∞%" o "+100%" engañaría.
     */
    public static function variacion(int|float $actual, int|float $anterior): ?float
    {
        if ((float) $anterior === 0.0) {
            return null;
        }

        return round(($actual - $anterior) / abs((float) $anterior) * 100, 1);
    }

    /** Etiqueta del periodo comparado: "ene" para un mes, "ene–ago" para varios. */
    private static function etiquetaPeriodo(int $hastaMes): string
    {
        if ($hastaMes <= 1) {
            return self::MESES_CORTOS[1];
        }

        return self::MESES_CORTOS[1].'–'.self::MESES_CORTOS[$hastaMes];
    }
}
