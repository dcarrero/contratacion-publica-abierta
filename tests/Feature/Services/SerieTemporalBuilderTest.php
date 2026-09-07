<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\Adjudicatario;
use App\Models\Contrato;
use App\Models\FuenteDatos;
use App\Models\Organismo;
use App\Services\SerieTemporalBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SerieTemporalBuilderTest extends TestCase
{
    use RefreshDatabase;

    private SerieTemporalBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        // paraNuts() prefiere la serie que deja `stats:recalculate` en disco: sin este fake
        // los tests leerían los JSON reales del entorno de desarrollo.
        Storage::fake('local');
        $this->builder = new SerieTemporalBuilder;
    }

    private function makeContrato(array $attrs): Contrato
    {
        static $seq = 0;
        $seq++;

        return Contrato::create(array_merge([
            'placsp_id' => 'SERIE-'.$seq,
            'fuente_datos_id' => FuenteDatos::where('slug', 'bquant-bootstrap')->firstOrFail()->id,
            'hash_contenido' => 'hash-serie-'.$seq,
        ], $attrs));
    }

    private function makeOrganismo(string $nif = 'Q1600001A'): Organismo
    {
        return Organismo::create([
            'nif' => $nif,
            'nombre' => 'Organismo '.$nif,
            'nombre_normalizado' => 'ORGANISMO '.$nif,
        ]);
    }

    private function makeAdjudicatario(string $nif = 'B33490426'): Adjudicatario
    {
        return Adjudicatario::create([
            'nif' => $nif,
            'nombre' => 'Empresa '.$nif,
            'nombre_normalizado' => 'EMPRESA '.$nif,
        ]);
    }

    // ----------------------------------------------------------------
    // Agrupación mensual
    // ----------------------------------------------------------------

    public function test_agrupa_por_anio_y_mes_para_un_adjudicatario(): void
    {
        $org = $this->makeOrganismo();
        $emp = $this->makeAdjudicatario();

        $this->makeContrato(['organismo_id' => $org->id, 'adjudicatario_id' => $emp->id, 'fecha_publicacion' => '2025-03-05', 'importe_adjudicacion' => 1000.00]);
        $this->makeContrato(['organismo_id' => $org->id, 'adjudicatario_id' => $emp->id, 'fecha_publicacion' => '2025-03-28', 'importe_adjudicacion' => 500.00]);
        $this->makeContrato(['organismo_id' => $org->id, 'adjudicatario_id' => $emp->id, 'fecha_publicacion' => '2026-01-15', 'importe_adjudicacion' => 2000.00]);

        $serie = $this->builder->paraAdjudicatario($emp->id);

        $this->assertSame([2025, 2026], $serie->aniosConDatos());
        $this->assertSame(['num' => 2, 'importe' => 1500.0], $serie->totalAnio(2025));
        $this->assertSame(['num' => 1, 'importe' => 2000.0], $serie->totalAnio(2026));

        $marzo = $serie->anio(2025, anioActual: 2026, mesActual: 9)[2];
        $this->assertSame(2, $marzo['num']);
        $this->assertSame(1500.0, $marzo['importe']);
    }

    public function test_no_mezcla_contratos_de_otras_entidades(): void
    {
        $org = $this->makeOrganismo();
        $otroOrg = $this->makeOrganismo('Q1600002B');
        $emp = $this->makeAdjudicatario();
        $otraEmp = $this->makeAdjudicatario('B00000000');

        $this->makeContrato(['organismo_id' => $org->id, 'adjudicatario_id' => $emp->id, 'fecha_publicacion' => '2025-03-05', 'importe_adjudicacion' => 1000.00]);
        $this->makeContrato(['organismo_id' => $otroOrg->id, 'adjudicatario_id' => $otraEmp->id, 'fecha_publicacion' => '2025-03-05', 'importe_adjudicacion' => 9999.00]);

        $this->assertSame(['num' => 1, 'importe' => 1000.0], $this->builder->paraAdjudicatario($emp->id)->totalAnio(2025));
        $this->assertSame(['num' => 1, 'importe' => 1000.0], $this->builder->paraOrganismo($org->id)->totalAnio(2025));
    }

    public function test_serie_por_organismo(): void
    {
        $org = $this->makeOrganismo();

        $this->makeContrato(['organismo_id' => $org->id, 'fecha_publicacion' => '2024-07-01', 'importe_adjudicacion' => 300.00]);
        $this->makeContrato(['organismo_id' => $org->id, 'fecha_publicacion' => '2024-07-02', 'importe_adjudicacion' => 200.00]);

        $serie = $this->builder->paraOrganismo($org->id);

        $this->assertSame(['num' => 2, 'importe' => 500.0], $serie->totalAnio(2024));
    }

    public function test_serie_por_nuts_incluye_prefijos_mas_largos(): void
    {
        $org = $this->makeOrganismo();

        $this->makeContrato(['organismo_id' => $org->id, 'nuts' => 'ES425', 'fecha_publicacion' => '2025-02-10', 'importe_adjudicacion' => 100.00]);
        $this->makeContrato(['organismo_id' => $org->id, 'nuts' => 'ES421', 'fecha_publicacion' => '2025-02-11', 'importe_adjudicacion' => 200.00]);
        $this->makeContrato(['organismo_id' => $org->id, 'nuts' => 'ES300', 'fecha_publicacion' => '2025-02-12', 'importe_adjudicacion' => 999.00]);

        $this->assertSame(['num' => 2, 'importe' => 300.0], $this->builder->paraNuts('ES42')->totalAnio(2025));
        $this->assertSame(['num' => 1, 'importe' => 100.0], $this->builder->paraNuts('ES425')->totalAnio(2025));
    }

    public function test_serie_nacional_agrega_todo(): void
    {
        $org = $this->makeOrganismo();

        $this->makeContrato(['organismo_id' => $org->id, 'nuts' => 'ES425', 'fecha_publicacion' => '2025-02-10', 'importe_adjudicacion' => 100.00]);
        $this->makeContrato(['organismo_id' => $org->id, 'nuts' => 'ES300', 'fecha_publicacion' => '2025-02-12', 'importe_adjudicacion' => 900.00]);

        $this->assertSame(['num' => 2, 'importe' => 1000.0], $this->builder->nacional()->totalAnio(2025));
    }

    public function test_para_nuts_prefiere_la_serie_precomputada_si_existe(): void
    {
        $org = $this->makeOrganismo();
        $this->makeContrato(['organismo_id' => $org->id, 'nuts' => 'ES425', 'fecha_publicacion' => '2025-02-10', 'importe_adjudicacion' => 100.00]);

        // Lo que dejó el último `stats:recalculate` manda sobre la consulta en vivo: es la
        // razón de precomputarlo (recorrer medio millón de filas por prefijo cuesta ~5 s).
        Storage::put('mapa-stats/admin-ES42.json', json_encode([
            'serie_mensual' => [['anio' => 2024, 'mes' => 6, 'num' => 7, 'importe' => 700.0]],
            'serie_sin_fecha' => 3,
        ]));

        $serie = $this->builder->paraNuts('ES42');

        $this->assertSame([2024], $serie->aniosConDatos());
        $this->assertSame(['num' => 7, 'importe' => 700.0], $serie->totales());
        $this->assertSame(3, $serie->sinFecha);
    }

    public function test_para_nuts_ignora_un_json_sin_serie_y_consulta_la_base(): void
    {
        $org = $this->makeOrganismo();
        $this->makeContrato(['organismo_id' => $org->id, 'nuts' => 'ES425', 'fecha_publicacion' => '2025-02-10', 'importe_adjudicacion' => 100.00]);

        // JSON de una versión anterior, sin serie_mensual: mejor recalcular que mentir.
        Storage::put('mapa-stats/admin-ES42.json', json_encode(['total_contratos' => 999]));

        $this->assertSame(['num' => 1, 'importe' => 100.0], $this->builder->paraNuts('ES42')->totales());
    }

    // ----------------------------------------------------------------
    // Casos de datos reales: importes nulos y contratos sin fecha
    // ----------------------------------------------------------------

    public function test_importe_nulo_cuenta_como_contrato_pero_suma_cero(): void
    {
        $org = $this->makeOrganismo();
        $emp = $this->makeAdjudicatario();

        $this->makeContrato(['organismo_id' => $org->id, 'adjudicatario_id' => $emp->id, 'fecha_publicacion' => '2025-04-01', 'importe_adjudicacion' => null]);
        $this->makeContrato(['organismo_id' => $org->id, 'adjudicatario_id' => $emp->id, 'fecha_publicacion' => '2025-04-02', 'importe_adjudicacion' => 750.00]);

        $this->assertSame(['num' => 2, 'importe' => 750.0], $this->builder->paraAdjudicatario($emp->id)->totalAnio(2025));
    }

    public function test_contratos_sin_fecha_quedan_fuera_de_la_serie_pero_se_cuentan(): void
    {
        $org = $this->makeOrganismo();
        $emp = $this->makeAdjudicatario();

        $this->makeContrato(['organismo_id' => $org->id, 'adjudicatario_id' => $emp->id, 'fecha_publicacion' => '2025-04-01', 'importe_adjudicacion' => 100.00]);
        $this->makeContrato(['organismo_id' => $org->id, 'adjudicatario_id' => $emp->id, 'fecha_publicacion' => null, 'importe_adjudicacion' => 5000.00]);
        $this->makeContrato(['organismo_id' => $org->id, 'adjudicatario_id' => $emp->id, 'fecha_publicacion' => null, 'importe_adjudicacion' => 1.00]);

        $serie = $this->builder->paraAdjudicatario($emp->id);

        $this->assertSame(['num' => 1, 'importe' => 100.0], $serie->totales());
        $this->assertSame(2, $serie->sinFecha);
    }

    public function test_entidad_sin_contratos_devuelve_serie_vacia(): void
    {
        $emp = $this->makeAdjudicatario();

        $serie = $this->builder->paraAdjudicatario($emp->id);

        $this->assertTrue($serie->estaVacia());
        $this->assertSame(0, $serie->sinFecha);
    }

    // ----------------------------------------------------------------
    // Coste: una sola consulta agrupada, no una por año
    // ----------------------------------------------------------------

    public function test_construye_la_serie_completa_con_una_sola_consulta(): void
    {
        $org = $this->makeOrganismo();
        $emp = $this->makeAdjudicatario();

        // Ocho años de actividad: si el builder consultara por año, se notaría.
        foreach (range(2018, 2025) as $anio) {
            foreach ([1, 6, 11] as $mes) {
                $this->makeContrato([
                    'organismo_id' => $org->id,
                    'adjudicatario_id' => $emp->id,
                    'fecha_publicacion' => sprintf('%d-%02d-05', $anio, $mes),
                    'importe_adjudicacion' => 100.00,
                ]);
            }
        }

        Cache::flush();
        DB::enableQueryLog();
        DB::flushQueryLog();

        $serie = $this->builder->paraAdjudicatario($emp->id);

        $consultas = DB::getQueryLog();
        DB::disableQueryLog();

        // Serie mensual y contratos sin fecha salen de la misma consulta agrupada.
        $this->assertCount(1, $consultas);
        $this->assertCount(8, $serie->aniosConDatos());
        $this->assertSame(24, $serie->totales()['num']);
    }

    public function test_la_segunda_llamada_se_sirve_de_cache(): void
    {
        $org = $this->makeOrganismo();
        $emp = $this->makeAdjudicatario();
        $this->makeContrato(['organismo_id' => $org->id, 'adjudicatario_id' => $emp->id, 'fecha_publicacion' => '2025-05-05', 'importe_adjudicacion' => 100.00]);

        $this->builder->paraAdjudicatario($emp->id);

        DB::enableQueryLog();
        DB::flushQueryLog();

        $serie = $this->builder->paraAdjudicatario($emp->id);

        $consultas = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(0, $consultas);
        $this->assertSame(['num' => 1, 'importe' => 100.0], $serie->totalAnio(2025));
    }

    public function test_cachea_por_entidad_sin_mezclar_claves(): void
    {
        $org = $this->makeOrganismo();
        $emp = $this->makeAdjudicatario();
        $otraEmp = $this->makeAdjudicatario('B11111111');

        $this->makeContrato(['organismo_id' => $org->id, 'adjudicatario_id' => $emp->id, 'fecha_publicacion' => '2025-05-05', 'importe_adjudicacion' => 100.00]);
        $this->makeContrato(['organismo_id' => $org->id, 'adjudicatario_id' => $otraEmp->id, 'fecha_publicacion' => '2025-05-05', 'importe_adjudicacion' => 700.00]);

        $this->assertSame(100.0, $this->builder->paraAdjudicatario($emp->id)->totalAnio(2025)['importe']);
        $this->assertSame(700.0, $this->builder->paraAdjudicatario($otraEmp->id)->totalAnio(2025)['importe']);

        // El organismo comparte id numérico con una de las empresas: las claves no colisionan.
        $this->assertSame(800.0, $this->builder->paraOrganismo($org->id)->totalAnio(2025)['importe']);
    }

    public function test_para_query_no_usa_cache(): void
    {
        $org = $this->makeOrganismo();
        $this->makeContrato(['organismo_id' => $org->id, 'fecha_publicacion' => '2025-05-05', 'importe_adjudicacion' => 100.00, 'es_menor' => true]);
        $this->makeContrato(['organismo_id' => $org->id, 'fecha_publicacion' => '2025-05-06', 'importe_adjudicacion' => 900.00, 'es_menor' => false]);

        $serie = $this->builder->paraQuery(fn () => Contrato::query()->menores());

        $this->assertSame(['num' => 1, 'importe' => 100.0], $serie->totalAnio(2025));

        // Sin caché: un cambio posterior se refleja de inmediato.
        $this->makeContrato(['organismo_id' => $org->id, 'fecha_publicacion' => '2025-05-07', 'importe_adjudicacion' => 50.00, 'es_menor' => true]);

        $this->assertSame(['num' => 2, 'importe' => 150.0], $this->builder->paraQuery(fn () => Contrato::query()->menores())->totalAnio(2025));
    }

    // ----------------------------------------------------------------
    // Integración con la comparativa
    // ----------------------------------------------------------------

    public function test_comparativa_ytd_sobre_datos_reales(): void
    {
        $org = $this->makeOrganismo();
        $emp = $this->makeAdjudicatario();

        // Enero–agosto 2025: 2 contratos. Enero–agosto 2026: 4. Septiembre 2026 no cuenta.
        $this->makeContrato(['organismo_id' => $org->id, 'adjudicatario_id' => $emp->id, 'fecha_publicacion' => '2025-02-01', 'importe_adjudicacion' => 100.00]);
        $this->makeContrato(['organismo_id' => $org->id, 'adjudicatario_id' => $emp->id, 'fecha_publicacion' => '2025-08-31', 'importe_adjudicacion' => 100.00]);
        $this->makeContrato(['organismo_id' => $org->id, 'adjudicatario_id' => $emp->id, 'fecha_publicacion' => '2025-12-01', 'importe_adjudicacion' => 999.00]);
        foreach (['2026-01-10', '2026-03-10', '2026-05-10', '2026-08-10'] as $fecha) {
            $this->makeContrato(['organismo_id' => $org->id, 'adjudicatario_id' => $emp->id, 'fecha_publicacion' => $fecha, 'importe_adjudicacion' => 100.00]);
        }
        $this->makeContrato(['organismo_id' => $org->id, 'adjudicatario_id' => $emp->id, 'fecha_publicacion' => '2026-09-02', 'importe_adjudicacion' => 5000.00]);

        $comp = $this->builder->paraAdjudicatario($emp->id)
            ->comparativaYtd(anioActual: 2026, mesActual: 9);

        $this->assertSame('ene–ago', $comp['periodo']);
        $this->assertSame(['num' => 4, 'importe' => 400.0], $comp['actual']);
        $this->assertSame(['num' => 2, 'importe' => 200.0], $comp['anterior']);
        $this->assertSame(100.0, $comp['variacion_num']);
    }

    public function test_entidad_inactiva_cae_al_ultimo_anio_con_datos(): void
    {
        $org = $this->makeOrganismo();
        $emp = $this->makeAdjudicatario();

        $this->makeContrato(['organismo_id' => $org->id, 'adjudicatario_id' => $emp->id, 'fecha_publicacion' => '2022-06-01', 'importe_adjudicacion' => 100.00]);

        $serie = $this->builder->paraAdjudicatario($emp->id);

        $this->assertTrue($serie->inactivaEn(2026));
        $this->assertSame(2022, $serie->anioReferencia(2026));
    }
}
