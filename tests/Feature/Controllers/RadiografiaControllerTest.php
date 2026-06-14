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
            ->assertSee($provincia->nombre);
    }

    public function test_show_provincia_inexistente_devuelve_404(): void
    {
        $this->get('/radiografia/provincia-que-no-existe')->assertStatus(404);
    }
}
