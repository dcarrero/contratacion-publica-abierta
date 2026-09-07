<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Adjudicatario;
use App\Models\Contrato;
use App\Models\FuenteDatos;
use App\Models\Organismo;
use App\Services\StatsRecalculator;
use App\Support\SqlDialect;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RecalculateStatsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('local');
    }

    // ----------------------------------------------------------------
    // Characterization helpers
    // ----------------------------------------------------------------

    private function makeFuente(): \App\Models\FuenteDatos
    {
        return FuenteDatos::where('slug', 'bquant-bootstrap')->firstOrFail();
    }

    private function makeOrganismo(string $nif, string $nombre): Organismo
    {
        return Organismo::create([
            'nif' => $nif,
            'nombre' => $nombre,
            'nombre_normalizado' => mb_strtoupper($nombre),
        ]);
    }

    private function makeAdjudicatario(string $nif, string $nombre): Adjudicatario
    {
        return Adjudicatario::create([
            'nif' => $nif,
            'nombre' => $nombre,
            'nombre_normalizado' => mb_strtoupper($nombre),
        ]);
    }

    private function makeContrato(array $attrs): Contrato
    {
        static $seq = 100;
        $seq++;

        return Contrato::create(array_merge([
            'placsp_id' => 'CHAR-'.$seq,
            'fuente_datos_id' => $this->makeFuente()->id,
            'hash_contenido' => 'hash-'.$seq,
        ], $attrs));
    }

    // ----------------------------------------------------------------
    // Characterization: mapa stats (ccaa.json)
    // ----------------------------------------------------------------

    public function test_mapa_ccaa_json_is_generated_with_correct_structure(): void
    {
        // Given: two contracts in Castilla-La Mancha (ES42*)
        $org = $this->makeOrganismo('Q1600001A', 'Junta CLM');
        $this->makeContrato([
            'organismo_id' => $org->id,
            'nif_organo' => 'Q1600001A',
            'nuts' => 'ES425',
            'importe_adjudicacion' => 10000.00,
        ]);
        $this->makeContrato([
            'organismo_id' => $org->id,
            'nif_organo' => 'Q1600001A',
            'nuts' => 'ES421',
            'importe_adjudicacion' => 20000.00,
        ]);

        $this->artisan('stats:recalculate', ['--entity' => 'mapa'])->assertSuccessful();

        Storage::assertExists('mapa-stats/ccaa.json');

        $ccaaData = json_decode(Storage::get('mapa-stats/ccaa.json'), true);
        $this->assertIsArray($ccaaData);
        // 19 CCAA seeded
        $this->assertCount(19, $ccaaData);

        // Every entry must have expected keys
        foreach ($ccaaData as $entry) {
            $this->assertArrayHasKey('nuts', $entry);
            $this->assertArrayHasKey('nombre', $entry);
            $this->assertArrayHasKey('total_contratos', $entry);
            $this->assertArrayHasKey('total_importe', $entry);
        }

        // Castilla-La Mancha (ES42) should aggregate both contracts
        $clm = collect($ccaaData)->firstWhere('nuts', 'ES42');
        $this->assertNotNull($clm, 'ES42 (Castilla-La Mancha) must be present');
        $this->assertSame(2, $clm['total_contratos']);
        $this->assertEquals(30000.00, $clm['total_importe']);
    }

    public function test_mapa_ccaa_json_nuts_not_matching_returns_zero(): void
    {
        // No contracts → all CCAA should have 0 totals
        $this->artisan('stats:recalculate', ['--entity' => 'mapa'])->assertSuccessful();

        $ccaaData = json_decode(Storage::get('mapa-stats/ccaa.json'), true);
        $totales = array_sum(array_column($ccaaData, 'total_contratos'));
        $this->assertSame(0, $totales);
    }

    public function test_mapa_provincias_json_generated_per_ccaa(): void
    {
        $org = $this->makeOrganismo('Q1600001A', 'Junta CLM');
        $this->makeContrato([
            'organismo_id' => $org->id,
            'nif_organo' => 'Q1600001A',
            'nuts' => 'ES425',
            'importe_adjudicacion' => 5000.00,
        ]);

        $this->artisan('stats:recalculate', ['--entity' => 'mapa'])->assertSuccessful();

        // There are 19 CCAA → 19 provincias-<nuts>.json files
        Storage::assertExists('mapa-stats/provincias-ES42.json');

        $provData = json_decode(Storage::get('mapa-stats/provincias-ES42.json'), true);
        $this->assertIsArray($provData);

        // Toledo is ES425, should have 1 contract
        $toledo = collect($provData)->firstWhere('nuts', 'ES425');
        $this->assertNotNull($toledo, 'Toledo (ES425) must be present in provincias-ES42.json');
        $this->assertSame(1, $toledo['total_contratos']);
        $this->assertEquals(5000.00, $toledo['total_importe']);
    }

    public function test_mapa_admin_json_generated_per_ccaa(): void
    {
        $org = $this->makeOrganismo('Q1600001A', 'Junta CLM');
        $this->makeContrato([
            'organismo_id' => $org->id,
            'nif_organo' => 'Q1600001A',
            'nuts' => 'ES425',
            'importe_adjudicacion' => 5000.00,
        ]);

        $this->artisan('stats:recalculate', ['--entity' => 'mapa'])->assertSuccessful();

        Storage::assertExists('mapa-stats/admin-ES42.json');

        $adminData = json_decode(Storage::get('mapa-stats/admin-ES42.json'), true);
        $this->assertArrayHasKey('total_contratos', $adminData);
        $this->assertArrayHasKey('total_importe', $adminData);
        $this->assertArrayHasKey('total_organismos', $adminData);
        $this->assertArrayHasKey('total_adjudicatarios', $adminData);
        $this->assertArrayHasKey('top_organismos_ids', $adminData);
        $this->assertSame(1, $adminData['total_contratos']);
    }

    // ----------------------------------------------------------------
    // Characterization: charts.json
    // ----------------------------------------------------------------

    public function test_charts_json_is_generated_with_top_level_keys(): void
    {
        $this->artisan('stats:recalculate', ['--entity' => 'charts'])->assertSuccessful();

        Storage::assertExists('mapa-stats/charts.json');

        $charts = json_decode(Storage::get('mapa-stats/charts.json'), true);
        $this->assertIsArray($charts);

        $expectedKeys = [
            'evolucion_anual',
            'distribucion_tipo',
            'distribucion_ccaa',
            'top_cpv',
            'umbral_menores',
            'evolucion_mensual',
            'concentracion',
            'generado_at',
        ];
        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $charts, "charts.json missing key: {$key}");
        }
    }

    public function test_charts_json_evolucion_anual_aggregates_correctly(): void
    {
        $org = $this->makeOrganismo('Q1600001A', 'Junta CLM');
        $fuente = $this->makeFuente();
        // Two contracts in 2024
        Contrato::create([
            'placsp_id' => 'CHART-Y1',
            'organismo_id' => $org->id,
            'nif_organo' => 'Q1600001A',
            'importe_adjudicacion' => 8000.00,
            'fecha_publicacion' => '2024-03-15',
            'fuente_datos_id' => $fuente->id,
            'hash_contenido' => 'h-chart-y1',
        ]);
        Contrato::create([
            'placsp_id' => 'CHART-Y2',
            'organismo_id' => $org->id,
            'nif_organo' => 'Q1600001A',
            'importe_adjudicacion' => 12000.00,
            'fecha_publicacion' => '2024-07-20',
            'fuente_datos_id' => $fuente->id,
            'hash_contenido' => 'h-chart-y2',
        ]);

        // Run mapa first (charts reads ccaa.json)
        $this->artisan('stats:recalculate', ['--entity' => 'mapa'])->assertSuccessful();
        $this->artisan('stats:recalculate', ['--entity' => 'charts'])->assertSuccessful();

        $charts = json_decode(Storage::get('mapa-stats/charts.json'), true);
        $anual = collect($charts['evolucion_anual'])->firstWhere('year', 2024);
        $this->assertNotNull($anual, '2024 must appear in evolucion_anual');
        $this->assertSame(2, $anual['num_contratos']);
        $this->assertEquals(20000.00, $anual['total_importe']);
    }

    public function test_charts_json_umbral_menores_contains_24_rangos(): void
    {
        $this->artisan('stats:recalculate', ['--entity' => 'charts'])->assertSuccessful();

        $charts = json_decode(Storage::get('mapa-stats/charts.json'), true);
        $this->assertCount(24, $charts['umbral_menores']);

        foreach ($charts['umbral_menores'] as $rango) {
            $this->assertArrayHasKey('rango', $rango);
            $this->assertArrayHasKey('min', $rango);
            $this->assertArrayHasKey('num_contratos', $rango);
        }
    }

    public function test_recalculate_organismos(): void
    {
        $organismo = Organismo::create([
            'nif' => 'P4527400A',
            'nombre' => 'Ayuntamiento de Toledo',
            'nombre_normalizado' => 'AYUNTAMIENTO DE TOLEDO',
            'total_contratos' => 0,
            'total_importe' => 0,
        ]);

        $fuente = FuenteDatos::where('slug', 'bquant-bootstrap')->first();

        Contrato::create([
            'placsp_id' => 'TEST-001',
            'organismo_id' => $organismo->id,
            'nif_organo' => 'P4527400A',
            'importe_adjudicacion' => 12100.00,
            'fuente_datos_id' => $fuente->id,
            'hash_contenido' => 'abc123',
        ]);

        Contrato::create([
            'placsp_id' => 'TEST-002',
            'organismo_id' => $organismo->id,
            'nif_organo' => 'P4527400A',
            'importe_adjudicacion' => 60500.00,
            'fuente_datos_id' => $fuente->id,
            'hash_contenido' => 'def456',
        ]);

        $this->artisan('stats:recalculate', ['--entity' => 'organismos'])
            ->assertSuccessful();

        $organismo->refresh();
        $this->assertSame(2, $organismo->total_contratos);
        $this->assertEquals(72600.00, (float) $organismo->total_importe);
    }

    public function test_recalculate_adjudicatarios(): void
    {
        $organismo = Organismo::create([
            'nif' => 'P4527400A',
            'nombre' => 'Ayuntamiento de Toledo',
            'nombre_normalizado' => 'AYUNTAMIENTO DE TOLEDO',
        ]);

        $adjudicatario = Adjudicatario::create([
            'nif' => 'B45123456',
            'nombre' => 'Papeleria Central S.L.',
            'nombre_normalizado' => 'PAPELERIA CENTRAL SL',
            'total_contratos' => 0,
            'total_importe' => 0,
        ]);

        $fuente = FuenteDatos::where('slug', 'bquant-bootstrap')->first();

        Contrato::create([
            'placsp_id' => 'TEST-003',
            'organismo_id' => $organismo->id,
            'adjudicatario_id' => $adjudicatario->id,
            'nif_organo' => 'P4527400A',
            'nif_adjudicatario' => 'B45123456',
            'importe_adjudicacion' => 9500.00,
            'fuente_datos_id' => $fuente->id,
            'hash_contenido' => 'ghi789',
        ]);

        Contrato::create([
            'placsp_id' => 'TEST-004',
            'organismo_id' => $organismo->id,
            'adjudicatario_id' => $adjudicatario->id,
            'nif_organo' => 'P4527400A',
            'nif_adjudicatario' => 'B45123456',
            'importe_adjudicacion' => 33000.00,
            'fuente_datos_id' => $fuente->id,
            'hash_contenido' => 'jkl012',
        ]);

        $this->artisan('stats:recalculate', ['--entity' => 'adjudicatarios'])
            ->assertSuccessful();

        $adjudicatario->refresh();
        $this->assertSame(2, $adjudicatario->total_contratos);
        $this->assertEquals(42500.00, (float) $adjudicatario->total_importe);
    }

    public function test_recalculate_all(): void
    {
        $organismo = Organismo::create([
            'nif' => 'P4527400A',
            'nombre' => 'Ayuntamiento de Toledo',
            'nombre_normalizado' => 'AYUNTAMIENTO DE TOLEDO',
        ]);

        $adjudicatario = Adjudicatario::create([
            'nif' => 'B45123456',
            'nombre' => 'Papeleria Central S.L.',
            'nombre_normalizado' => 'PAPELERIA CENTRAL SL',
        ]);

        $fuente = FuenteDatos::where('slug', 'bquant-bootstrap')->first();

        Contrato::create([
            'placsp_id' => 'TEST-005',
            'organismo_id' => $organismo->id,
            'adjudicatario_id' => $adjudicatario->id,
            'nif_organo' => 'P4527400A',
            'nif_adjudicatario' => 'B45123456',
            'importe_adjudicacion' => 50000.00,
            'fuente_datos_id' => $fuente->id,
            'hash_contenido' => 'mno345',
        ]);

        $this->artisan('stats:recalculate')
            ->assertSuccessful();

        $organismo->refresh();
        $adjudicatario->refresh();

        $this->assertSame(1, $organismo->total_contratos);
        $this->assertEquals(50000.00, (float) $organismo->total_importe);
        $this->assertSame(1, $adjudicatario->total_contratos);
        $this->assertEquals(50000.00, (float) $adjudicatario->total_importe);
    }

    public function test_recalculate_resets_zeroes_when_no_contratos(): void
    {
        $organismo = Organismo::create([
            'nif' => 'P4527400A',
            'nombre' => 'Ayuntamiento de Toledo',
            'nombre_normalizado' => 'AYUNTAMIENTO DE TOLEDO',
            'total_contratos' => 99,
            'total_importe' => 999999.00,
        ]);

        $this->artisan('stats:recalculate', ['--entity' => 'organismos'])
            ->assertSuccessful();

        $organismo->refresh();
        $this->assertSame(0, $organismo->total_contratos);
        $this->assertEquals(0.00, (float) $organismo->total_importe);
    }

    public function test_recalculate_handles_null_importes(): void
    {
        $organismo = Organismo::create([
            'nif' => 'P4527400A',
            'nombre' => 'Ayuntamiento de Toledo',
            'nombre_normalizado' => 'AYUNTAMIENTO DE TOLEDO',
        ]);

        $fuente = FuenteDatos::where('slug', 'bquant-bootstrap')->first();

        Contrato::create([
            'placsp_id' => 'TEST-006',
            'organismo_id' => $organismo->id,
            'nif_organo' => 'P4527400A',
            'importe_adjudicacion' => null,
            'fuente_datos_id' => $fuente->id,
            'hash_contenido' => 'pqr678',
        ]);

        $this->artisan('stats:recalculate', ['--entity' => 'organismos'])
            ->assertSuccessful();

        $organismo->refresh();
        $this->assertSame(1, $organismo->total_contratos);
        $this->assertEquals(0.00, (float) $organismo->total_importe);
    }

    // ----------------------------------------------------------------
    // Sparklines: serie mensual del top del año
    // ----------------------------------------------------------------

    /**
     * @return list<array{nombre: string, nif: string, num_contratos: int, total_importe: float, serie: list<float>}>
     */
    private function topDelAnio(int $anio, string $columna, string $tabla): array
    {
        $metodo = new \ReflectionMethod(StatsRecalculator::class, 'topDelAnio');

        /** @var list<array{nombre: string, nif: string, num_contratos: int, total_importe: float, serie: list<float>}> $top */
        $top = $metodo->invoke(new StatsRecalculator, $anio, $columna, $tabla);

        return $top;
    }

    public function test_top_del_anio_builds_a_twelve_month_serie(): void
    {
        $org = $this->makeOrganismo('Q1600001A', 'Junta CLM');
        $empresa = $this->makeAdjudicatario('B12345674', 'Empresa Serie SL');

        $this->makeContrato([
            'organismo_id' => $org->id,
            'adjudicatario_id' => $empresa->id,
            'fecha_publicacion' => '2024-02-10',
            'importe_adjudicacion' => 1000.00,
        ]);
        $this->makeContrato([
            'organismo_id' => $org->id,
            'adjudicatario_id' => $empresa->id,
            'fecha_publicacion' => '2024-02-20',
            'importe_adjudicacion' => 500.00,
        ]);
        $this->makeContrato([
            'organismo_id' => $org->id,
            'adjudicatario_id' => $empresa->id,
            'fecha_publicacion' => '2024-12-31',
            'importe_adjudicacion' => 2000.00,
        ]);
        // Fuera del año: no debe aparecer en la serie de 2024.
        $this->makeContrato([
            'organismo_id' => $org->id,
            'adjudicatario_id' => $empresa->id,
            'fecha_publicacion' => '2023-02-01',
            'importe_adjudicacion' => 9999.00,
        ]);

        $top = $this->topDelAnio(2024, 'adjudicatario_id', 'adjudicatarios');

        $this->assertCount(1, $top);
        $this->assertCount(12, $top[0]['serie']);
        $this->assertSame(
            [0.0, 1500.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 2000.0],
            $top[0]['serie']
        );
    }

    public function test_top_del_anio_builds_all_series_in_a_single_query(): void
    {
        $org = $this->makeOrganismo('Q1600001A', 'Junta CLM');

        foreach (['B12345674', 'B44531218', 'B98765432'] as $i => $nif) {
            $empresa = $this->makeAdjudicatario($nif, 'Empresa '.$nif);
            $this->makeContrato([
                'organismo_id' => $org->id,
                'adjudicatario_id' => $empresa->id,
                'fecha_publicacion' => sprintf('2024-%02d-05', $i + 1),
                'importe_adjudicacion' => 1000.00 * ($i + 1),
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $top = $this->topDelAnio(2024, 'adjudicatario_id', 'adjudicatarios');

        $consultas = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(3, $top);

        $mensuales = array_filter(
            $consultas,
            fn (array $c) => str_contains($c['query'], SqlDialect::yearMonth('fecha_publicacion'))
        );
        $this->assertCount(1, $mensuales, 'La serie mensual del top debe salir de una única consulta');

        // Agregado + nombres + NIFs + serie: sin N+1 por entidad.
        $this->assertCount(4, $consultas);
    }

    public function test_charts_json_top_empresas_includes_serie(): void
    {
        $org = $this->makeOrganismo('Q1600001A', 'Junta CLM');
        $empresa = $this->makeAdjudicatario('B12345674', 'Empresa Charts SL');
        $this->makeContrato([
            'organismo_id' => $org->id,
            'adjudicatario_id' => $empresa->id,
            'fecha_publicacion' => date('Y').'-03-15',
            'importe_adjudicacion' => 4000.00,
        ]);

        $this->artisan('stats:recalculate', ['--entity' => 'charts'])->assertSuccessful();

        $charts = json_decode(Storage::get('mapa-stats/charts.json'), true);
        $serie = $charts['anio_actual']['top_empresas'][0]['serie'] ?? null;

        $this->assertIsArray($serie);
        $this->assertCount(12, $serie);
        // json_encode colapsa 4000.0 a 4000: comparación laxa a propósito.
        $this->assertEquals(4000.0, $serie[2]);
    }

    public function test_recalculate_invalid_entity_fails(): void
    {
        $this->artisan('stats:recalculate', ['--entity' => 'invalid'])
            ->assertFailed();
    }
}
