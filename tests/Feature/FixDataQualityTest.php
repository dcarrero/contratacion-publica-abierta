<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Contrato;
use App\Models\FuenteDatos;
use App\Models\Organismo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FixDataQualityTest extends TestCase
{
    use RefreshDatabase;

    private Organismo $organismo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->organismo = Organismo::create([
            'nif' => 'Q1600001A',
            'nombre' => 'Junta CLM',
            'nombre_normalizado' => 'JUNTA CLM',
        ]);
    }

    private function makeContrato(array $attrs): Contrato
    {
        static $seq = 100;
        $seq++;

        return Contrato::create(array_merge([
            'placsp_id' => 'CHAR-'.$seq,
            'fuente_datos_id' => FuenteDatos::where('slug', 'bquant-bootstrap')->firstOrFail()->id,
            'organismo_id' => $this->organismo->id,
            'nif_organo' => $this->organismo->nif,
            'hash_contenido' => 'hash-'.$seq,
        ], $attrs));
    }

    public function test_nullifies_absurd_dates_in_all_columns_and_keeps_valid(): void
    {
        $futura = $this->makeContrato(['fecha_publicacion' => '2099-12-27']);
        $antigua = $this->makeContrato(['fecha_adjudicacion' => '0218-07-12']);
        $formAntigua = $this->makeContrato(['fecha_formalizacion' => '1850-01-01']);
        $valida = $this->makeContrato([
            'fecha_publicacion' => '2024-05-01',
            'fecha_adjudicacion' => '2024-06-01',
            'fecha_formalizacion' => '2024-07-01',
        ]);

        $this->artisan('data:fix-quality')->assertSuccessful();

        $this->assertNull($futura->fresh()->fecha_publicacion);
        $this->assertNull($antigua->fresh()->fecha_adjudicacion);
        $this->assertNull($formAntigua->fresh()->fecha_formalizacion);

        $valida = $valida->fresh();
        $this->assertNotNull($valida->fecha_publicacion);
        $this->assertNotNull($valida->fecha_adjudicacion);
        $this->assertNotNull($valida->fecha_formalizacion);
    }

    public function test_fecha_limite_allows_future_deadlines_up_to_2100(): void
    {
        $plazoFuturo = $this->makeContrato(['fecha_limite' => '2030-03-15']);
        $plazoImposible = $this->makeContrato(['fecha_limite' => '2199-01-01']);

        $this->artisan('data:fix-quality')->assertSuccessful();

        // Un plazo de presentación a 2030 es legítimo y debe conservarse.
        $this->assertNotNull($plazoFuturo->fresh()->fecha_limite);
        // Por encima de 2100 es basura del origen → NULL.
        $this->assertNull($plazoImposible->fresh()->fecha_limite);
    }

    public function test_dry_run_does_not_modify_data(): void
    {
        $futura = $this->makeContrato(['fecha_publicacion' => '2099-12-27']);

        $this->artisan('data:fix-quality', ['--dry-run' => true])->assertSuccessful();

        $this->assertNotNull($futura->fresh()->fecha_publicacion);
    }
}
