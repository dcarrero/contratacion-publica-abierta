<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\SerieTemporal;
use PHPUnit\Framework\TestCase;

class SerieTemporalTest extends TestCase
{
    /**
     * @param  list<array{int, int, int, float}>  $filas  [anio, mes, num, importe]
     */
    private function serie(array $filas, int $sinFecha = 0): SerieTemporal
    {
        return new SerieTemporal(
            array_map(
                fn (array $f) => ['anio' => $f[0], 'mes' => $f[1], 'num' => $f[2], 'importe' => $f[3]],
                $filas
            ),
            $sinFecha
        );
    }

    // ----------------------------------------------------------------
    // Construcción y consultas básicas
    // ----------------------------------------------------------------

    public function test_serie_vacia(): void
    {
        $serie = $this->serie([]);

        $this->assertTrue($serie->estaVacia());
        $this->assertSame([], $serie->aniosConDatos());
        $this->assertNull($serie->primerAnio());
        $this->assertNull($serie->ultimoAnio());
        $this->assertNull($serie->mejorMes());
        $this->assertNull($serie->mejorAnio());
        $this->assertNull($serie->ultimoMesConDatos());
        $this->assertNull($serie->anioReferencia(2026));
        $this->assertSame(['num' => 0, 'importe' => 0.0], $serie->totales());
    }

    public function test_ordena_anios_y_meses_llegen_como_lleguen(): void
    {
        $serie = $this->serie([
            [2025, 12, 1, 100.0],
            [2023, 3, 2, 200.0],
            [2025, 1, 3, 300.0],
        ]);

        $this->assertSame([2023, 2025], $serie->aniosConDatos());
        $this->assertSame(2023, $serie->primerAnio());
        $this->assertSame(2025, $serie->ultimoAnio());
    }

    public function test_acumula_filas_repetidas_del_mismo_mes(): void
    {
        $serie = $this->serie([
            [2025, 5, 2, 100.0],
            [2025, 5, 3, 50.0],
        ]);

        $this->assertSame(['num' => 5, 'importe' => 150.0], $serie->totalAnio(2025));
    }

    public function test_descarta_meses_fuera_de_rango(): void
    {
        $serie = $this->serie([
            [2025, 0, 5, 100.0],
            [2025, 13, 5, 100.0],
            [2025, 6, 1, 10.0],
        ]);

        $this->assertSame(['num' => 1, 'importe' => 10.0], $serie->totalAnio(2025));
    }

    public function test_expone_contratos_sin_fecha(): void
    {
        $this->assertSame(42, $this->serie([[2025, 1, 1, 10.0]], 42)->sinFecha);
    }

    // ----------------------------------------------------------------
    // Entidades inactivas: el caso mayoritario en empresas de cola larga
    // ----------------------------------------------------------------

    public function test_anio_referencia_es_el_anio_en_curso_si_hay_datos(): void
    {
        $serie = $this->serie([[2025, 1, 1, 10.0], [2026, 2, 1, 10.0]]);

        $this->assertSame(2026, $serie->anioReferencia(2026));
        $this->assertFalse($serie->inactivaEn(2026));
    }

    public function test_anio_referencia_cae_al_ultimo_anio_con_datos_si_no_hay_actividad(): void
    {
        $serie = $this->serie([[2021, 4, 3, 500.0], [2023, 7, 1, 100.0]]);

        $this->assertSame(2023, $serie->anioReferencia(2026));
        $this->assertTrue($serie->inactivaEn(2026));
    }

    public function test_serie_vacia_no_se_considera_inactiva(): void
    {
        // Sin ningún contrato no procede el aviso de "sin actividad en 2026":
        // la ficha entera está vacía, que es otro mensaje distinto.
        $this->assertFalse($this->serie([])->inactivaEn(2026));
    }

    // ----------------------------------------------------------------
    // Totales por año
    // ----------------------------------------------------------------

    public function test_anios_devuelve_totales_descendentes_con_variacion_y_peso(): void
    {
        $serie = $this->serie([
            [2024, 1, 10, 1000.0],
            [2025, 1, 15, 1500.0],
        ]);

        $anios = $serie->anios();

        $this->assertSame(2025, $anios[0]['anio']);
        $this->assertSame(2024, $anios[1]['anio']);

        $this->assertSame(15, $anios[0]['num']);
        $this->assertSame(50.0, $anios[0]['variacion_num']);
        $this->assertSame(50.0, $anios[0]['variacion_importe']);
        $this->assertSame(60.0, $anios[0]['pct_total_num']);
        $this->assertSame(60.0, $anios[0]['pct_total_importe']);

        // El año más antiguo no tiene contra qué compararse.
        $this->assertNull($anios[1]['variacion_num']);
    }

    public function test_variacion_es_null_cuando_el_anio_anterior_no_tiene_datos(): void
    {
        // 2023 falta por completo: 2024 no compara contra cero, compara contra nada.
        $anios = $this->serie([[2022, 1, 5, 500.0], [2024, 1, 10, 1000.0]])->anios();

        $this->assertSame(2024, $anios[0]['anio']);
        $this->assertNull($anios[0]['variacion_num']);
    }

    // ----------------------------------------------------------------
    // Meses de un año
    // ----------------------------------------------------------------

    public function test_anio_devuelve_los_doce_meses_incluidos_los_vacios(): void
    {
        $meses = $this->serie([[2025, 3, 4, 400.0]])->anio(2025, anioActual: 2026, mesActual: 9);

        $this->assertCount(12, $meses);
        $this->assertSame(4, $meses[2]['num']);
        $this->assertSame('mar', $meses[2]['etiqueta']);
        $this->assertSame(0, $meses[0]['num']);
        $this->assertSame(0.0, $meses[0]['importe']);
    }

    public function test_marca_mes_en_curso_como_parcial_y_los_siguientes_como_futuros(): void
    {
        $meses = $this->serie([[2026, 9, 2, 200.0]])->anio(2026, anioActual: 2026, mesActual: 9);

        $this->assertFalse($meses[7]['parcial']);   // agosto: cerrado
        $this->assertFalse($meses[7]['futuro']);
        $this->assertTrue($meses[8]['parcial']);    // septiembre: en curso
        $this->assertFalse($meses[8]['futuro']);
        $this->assertFalse($meses[9]['parcial']);   // octubre: aún no ha pasado
        $this->assertTrue($meses[9]['futuro']);
    }

    public function test_un_anio_pasado_no_tiene_meses_parciales_ni_futuros(): void
    {
        $meses = $this->serie([[2024, 6, 1, 10.0]])->anio(2024, anioActual: 2026, mesActual: 9);

        foreach ($meses as $mes) {
            $this->assertFalse($mes['parcial']);
            $this->assertFalse($mes['futuro']);
        }
    }

    public function test_meses_de_alinea_varias_series(): void
    {
        $serie = $this->serie([[2025, 2, 1, 100.0], [2026, 2, 3, 300.0]]);

        $series = $serie->mesesDe([2026, 2025], anioActual: 2026, mesActual: 9);

        $this->assertSame([2026, 2025], array_keys($series));
        $this->assertCount(12, $series[2025]);
        $this->assertSame(3, $series[2026][1]['num']);
        $this->assertSame(1, $series[2025][1]['num']);
    }

    // ----------------------------------------------------------------
    // Comparativa YTD: el corazón del replanteo
    // ----------------------------------------------------------------

    public function test_ytd_compara_el_mismo_tramo_de_meses_no_el_anio_completo(): void
    {
        // 2025 tiene 12 meses de actividad; 2026 sólo ha llegado a septiembre.
        // Comparar 2026 entero contra 2025 entero fingiría un desplome del 25%.
        $filas = [];
        for ($mes = 1; $mes <= 12; $mes++) {
            $filas[] = [2025, $mes, 10, 1000.0];
        }
        for ($mes = 1; $mes <= 9; $mes++) {
            $filas[] = [2026, $mes, 10, 1000.0];
        }

        $comp = $this->serie($filas)->comparativaYtd(anioActual: 2026, mesActual: 9);

        // Sólo meses cerrados: enero–agosto a ambos lados.
        $this->assertSame(2026, $comp['anio']);
        $this->assertSame(8, $comp['hasta_mes']);
        $this->assertSame('ene–ago', $comp['periodo']);
        $this->assertFalse($comp['completo']);
        $this->assertSame(['num' => 80, 'importe' => 8000.0], $comp['actual']);
        $this->assertSame(['num' => 80, 'importe' => 8000.0], $comp['anterior']);
        $this->assertSame(0.0, $comp['variacion_num']);
        $this->assertSame(0.0, $comp['variacion_importe']);
    }

    public function test_ytd_excluye_el_mes_en_curso_por_estar_incompleto(): void
    {
        $comp = $this->serie([
            [2025, 8, 10, 1000.0],
            [2026, 8, 20, 2000.0],
            [2026, 9, 999, 999999.0],   // mes en curso: no debe contar
        ])->comparativaYtd(anioActual: 2026, mesActual: 9);

        $this->assertSame(['num' => 20, 'importe' => 2000.0], $comp['actual']);
        $this->assertSame(100.0, $comp['variacion_num']);
    }

    public function test_ytd_en_enero_compara_el_anio_anterior_completo(): void
    {
        // En enero no hay ningún mes cerrado de 2026: la comparativa útil es 2025 vs 2024.
        $comp = $this->serie([
            [2024, 5, 10, 1000.0],
            [2025, 5, 12, 1500.0],
            [2026, 1, 1, 50.0],
        ])->comparativaYtd(anioActual: 2026, mesActual: 1);

        $this->assertSame('enero', $comp['fallback']);
        $this->assertSame(2025, $comp['anio']);
        $this->assertSame(2024, $comp['anio_anterior']);
        $this->assertSame(12, $comp['hasta_mes']);
        $this->assertTrue($comp['completo']);
        $this->assertSame(['num' => 12, 'importe' => 1500.0], $comp['actual']);
        $this->assertSame(['num' => 10, 'importe' => 1000.0], $comp['anterior']);
        $this->assertSame(20.0, $comp['variacion_num']);
    }

    public function test_ytd_en_febrero_compara_solo_enero(): void
    {
        $comp = $this->serie([[2026, 1, 5, 500.0], [2025, 1, 4, 400.0]])
            ->comparativaYtd(anioActual: 2026, mesActual: 2);

        $this->assertNull($comp['fallback']);
        $this->assertSame(1, $comp['hasta_mes']);
        $this->assertSame('ene', $comp['periodo']);
        $this->assertSame(25.0, $comp['variacion_num']);
    }

    public function test_ytd_de_un_anio_pasado_compara_anios_completos(): void
    {
        $comp = $this->serie([
            [2023, 11, 10, 1000.0],
            [2024, 11, 20, 3000.0],
        ])->comparativaYtd(2024, anioActual: 2026, mesActual: 9);

        $this->assertSame(2024, $comp['anio']);
        $this->assertSame(12, $comp['hasta_mes']);
        $this->assertTrue($comp['completo']);
        $this->assertSame(100.0, $comp['variacion_num']);
        $this->assertSame(200.0, $comp['variacion_importe']);
    }

    public function test_ytd_sin_datos_en_el_anio_anterior_no_inventa_porcentaje(): void
    {
        $comp = $this->serie([[2026, 3, 5, 500.0]])->comparativaYtd(anioActual: 2026, mesActual: 9);

        $this->assertSame(['num' => 5, 'importe' => 500.0], $comp['actual']);
        $this->assertSame(['num' => 0, 'importe' => 0.0], $comp['anterior']);
        $this->assertNull($comp['variacion_num']);
        $this->assertSame(5, $comp['variacion_num_abs']);
        $this->assertSame(500.0, $comp['variacion_importe_abs']);
    }

    public function test_ytd_detecta_caida_a_cero(): void
    {
        $comp = $this->serie([[2025, 3, 5, 500.0]])->comparativaYtd(anioActual: 2026, mesActual: 9);

        $this->assertSame(['num' => 0, 'importe' => 0.0], $comp['actual']);
        $this->assertSame(-100.0, $comp['variacion_num']);
        $this->assertSame(-5, $comp['variacion_num_abs']);
    }

    public function test_acumulado_hasta_corta_por_mes(): void
    {
        $serie = $this->serie([[2025, 1, 1, 100.0], [2025, 6, 2, 200.0], [2025, 12, 4, 400.0]]);

        $this->assertSame(['num' => 1, 'importe' => 100.0], $serie->acumuladoHasta(2025, 5));
        $this->assertSame(['num' => 3, 'importe' => 300.0], $serie->acumuladoHasta(2025, 6));
        $this->assertSame(['num' => 7, 'importe' => 700.0], $serie->acumuladoHasta(2025, 12));
        $this->assertSame(['num' => 0, 'importe' => 0.0], $serie->acumuladoHasta(2019, 12));
    }

    // ----------------------------------------------------------------
    // Récords y matriz del histórico
    // ----------------------------------------------------------------

    public function test_mejor_mes_y_mejor_anio_por_importe(): void
    {
        $serie = $this->serie([
            [2024, 2, 50, 1000.0],
            [2025, 7, 1, 9000.0],
            [2025, 8, 1, 100.0],
        ]);

        $mejorMes = $serie->mejorMes();
        $this->assertSame(2025, $mejorMes['anio']);
        $this->assertSame(7, $mejorMes['mes']);
        $this->assertSame('jul', $mejorMes['etiqueta']);

        $mejorAnio = $serie->mejorAnio();
        $this->assertSame(2025, $mejorAnio['anio']);
        $this->assertSame(9100.0, $mejorAnio['importe']);
    }

    public function test_ultimo_mes_con_datos(): void
    {
        $ultimo = $this->serie([[2025, 3, 1, 10.0], [2026, 2, 7, 700.0]])->ultimoMesConDatos();

        $this->assertSame(2026, $ultimo['anio']);
        $this->assertSame(2, $ultimo['mes']);
        $this->assertSame(7, $ultimo['num']);
    }

    public function test_matriz_anio_mes_para_el_heatmap(): void
    {
        $matriz = $this->serie([
            [2025, 1, 3, 300.0],
            [2026, 5, 8, 800.0],
        ])->matrizAnioMes(anioActual: 2026, mesActual: 9);

        // Años descendentes: lo más reciente arriba.
        $this->assertSame(2026, $matriz['filas'][0]['anio']);
        $this->assertSame(2025, $matriz['filas'][1]['anio']);
        $this->assertCount(12, $matriz['filas'][0]['meses']);

        // Máximos para normalizar la intensidad del color.
        $this->assertSame(8, $matriz['max_num']);
        $this->assertSame(800.0, $matriz['max_importe']);

        $this->assertSame(['num' => 8, 'importe' => 800.0], $matriz['filas'][0]['total']);
    }

    // ----------------------------------------------------------------
    // Variación
    // ----------------------------------------------------------------

    public function test_variacion_desde_cero_es_null_no_infinito(): void
    {
        $this->assertNull(SerieTemporal::variacion(10, 0));
        $this->assertNull(SerieTemporal::variacion(0, 0));
        $this->assertSame(-100.0, SerieTemporal::variacion(0, 10));
        $this->assertSame(50.0, SerieTemporal::variacion(15, 10));
        $this->assertSame(-33.3, SerieTemporal::variacion(2, 3));
    }
}
