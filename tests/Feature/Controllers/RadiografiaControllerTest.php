<?php

declare(strict_types=1);

namespace Tests\Feature\Controllers;

use App\Models\Adjudicatario;
use App\Models\Contrato;
use App\Models\FuenteDatos;
use App\Models\Organismo;
use App\Models\Provincia;
use App\Services\InformeDataBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class RadiografiaControllerTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->withoutVite();
    }

    private function provinciaConNuts(): Provincia
    {
        return Provincia::whereNotNull('nuts')->orderBy('nombre')->firstOrFail();
    }

    private function contratoEnNuts(string $nuts, float $importe, int $adjId, int $orgId): void
    {
        self::$seq++;
        Contrato::create([
            'placsp_id' => 'RAD-'.self::$seq,
            'url_placsp' => 'https://example.com/'.self::$seq,
            'organismo_id' => $orgId,
            'adjudicatario_id' => $adjId,
            'nuts' => $nuts,
            'importe_adjudicacion' => $importe,
            'fecha_publicacion' => '2024-03-01',
            'fuente_datos_id' => FuenteDatos::firstOrCreate(
                ['slug' => 'test-rad'],
                ['nombre' => 'Test', 'tipo' => 'atom', 'activo' => true]
            )->id,
            'version' => 1,
        ]);
    }

    private function contratoEnNuts2(string $nuts, float $importe, int $adjId, int $orgId, string $fecha): void
    {
        self::$seq++;
        Contrato::create([
            'placsp_id' => 'RADY-'.self::$seq,
            'url_placsp' => 'https://example.com/y'.self::$seq,
            'organismo_id' => $orgId,
            'adjudicatario_id' => $adjId,
            'nuts' => $nuts,
            'importe_adjudicacion' => $importe,
            'fecha_publicacion' => $fecha,
            'fuente_datos_id' => FuenteDatos::firstOrCreate(
                ['slug' => 'test-rad'],
                ['nombre' => 'Test', 'tipo' => 'atom', 'activo' => true]
            )->id,
            'version' => 1,
        ]);
    }

    public function test_build_provincia_calcula_kpis_y_per_capita(): void
    {
        $provincia = $this->provinciaConNuts();
        $provincia->update(['poblacion' => 1000000]);

        $org = Organismo::create(['nif' => 'P1111111A', 'nombre' => 'Diputación Test']);
        $adj = Adjudicatario::create(['nif' => 'B11111111', 'nombre' => 'Constructora Test SL']);

        $this->contratoEnNuts($provincia->nuts, 600000, $adj->id, $org->id);
        $this->contratoEnNuts($provincia->nuts, 400000, $adj->id, $org->id);
        // Contrato fuera de la provincia (otro NUTS) → no debe contar.
        $this->contratoEnNuts('ESZZZ', 999999, $adj->id, $org->id);

        $data = app(InformeDataBuilder::class)->buildProvincia($provincia->fresh());

        $this->assertSame(2, $data['kpis']['total_contratos']);
        $this->assertEqualsWithDelta(1000000.0, $data['kpis']['total_importe'], 0.01);
        // 1.000.000 € / 1.000.000 hab = 1 €/hab
        $this->assertEqualsWithDelta(1.0, $data['kpis']['gasto_per_capita'], 0.01);
    }

    public function test_build_provincia_por_anio_filtra_y_compara_con_anterior(): void
    {
        $provincia = $this->provinciaConNuts();
        $provincia->update(['poblacion' => 1000000]);

        $org = Organismo::create(['nif' => 'P2222222B', 'nombre' => 'Org YoY']);
        $adj = Adjudicatario::create(['nif' => 'B22222222', 'nombre' => 'Empresa YoY SL']);

        // 2024: 2 contratos, 100.000 €
        $this->contratoEnNuts2($provincia->nuts, 60000, $adj->id, $org->id, '2024-03-01');
        $this->contratoEnNuts2($provincia->nuts, 40000, $adj->id, $org->id, '2024-06-01');
        // 2023: 1 contrato, 50.000 €
        $this->contratoEnNuts2($provincia->nuts, 50000, $adj->id, $org->id, '2023-05-01');

        $data = app(InformeDataBuilder::class)->buildProvincia($provincia->fresh(), 2024);

        // KPIs del año 2024
        $this->assertSame(2024, $data['year']);
        $this->assertSame(2, $data['kpis']['total_contratos']);
        $this->assertEqualsWithDelta(100000.0, $data['kpis']['total_importe'], 0.01);

        // Años disponibles incluye 2024 y 2023
        $this->assertContains(2024, $data['anios_disponibles']);
        $this->assertContains(2023, $data['anios_disponibles']);

        // Comparativa con 2023: importe 100k vs 50k → +100%
        $this->assertSame(2023, $data['comparativa']['year_anterior']);
        $this->assertEqualsWithDelta(50000.0, $data['comparativa']['importe']['anterior'], 0.01);
        $this->assertEqualsWithDelta(100.0, $data['comparativa']['importe']['delta_pct'], 0.1);
    }

    public function test_show_con_year_en_la_ruta_devuelve_200(): void
    {
        $provincia = $this->provinciaConNuts();
        $slug = Str::slug($provincia->nombre);

        $this->get("/radiografia/{$slug}/2024")->assertStatus(200);
    }

    public function test_query_year_antiguo_redirige_a_url_seo(): void
    {
        $provincia = $this->provinciaConNuts();
        $slug = Str::slug($provincia->nombre);

        $this->get("/radiografia/{$slug}?year=2024")
            ->assertStatus(301)
            ->assertRedirect("/radiografia/{$slug}/2024");
    }

    public function test_index_devuelve_200(): void
    {
        $this->get('/radiografia')->assertStatus(200);
    }

    public function test_show_provincia_existente_devuelve_200(): void
    {
        $provincia = $this->provinciaConNuts();
        $slug = Str::slug($provincia->nombre);

        $this->get("/radiografia/{$slug}")
            ->assertStatus(200)
            ->assertSee($provincia->nombre)
            ->assertSee('rel="canonical"', false)
            ->assertSee('name="description"', false);
    }

    public function test_show_provincia_inexistente_devuelve_404(): void
    {
        $this->get('/radiografia/provincia-que-no-existe')->assertStatus(404);
    }
}
