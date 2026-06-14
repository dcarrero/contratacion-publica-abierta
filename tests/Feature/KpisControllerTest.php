<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Adjudicatario;
use App\Models\Contrato;
use App\Models\FuenteDatos;
use App\Models\Organismo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KpisControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function createFuente(): FuenteDatos
    {
        return FuenteDatos::create([
            'nombre' => 'Test Fuente',
            'slug' => 'test-fuente',
            'tipo' => 'atom',
            'activo' => true,
        ]);
    }

    private function createOrganismo(): Organismo
    {
        return Organismo::create([
            'nif' => 'P0000001A',
            'nombre' => 'Organismo Test',
        ]);
    }

    private function createAdjudicatario(): Adjudicatario
    {
        return Adjudicatario::create([
            'nif' => 'B12345678',
            'nombre' => 'Empresa Test',
        ]);
    }

    private function createContrato(int $organismoId, int $adjudicatarioId, int $fuenteId, float $importe, bool $esMenor, int $seq): Contrato
    {
        return Contrato::create([
            'placsp_id' => "TEST-{$seq}-".uniqid(),
            'url_placsp' => 'https://example.com',
            'organismo_id' => $organismoId,
            'adjudicatario_id' => $adjudicatarioId,
            'importe_adjudicacion' => $importe,
            'es_menor' => $esMenor,
            'fuente_datos_id' => $fuenteId,
            'version' => 1,
        ]);
    }

    // --- OrganismoController tests ---

    public function test_organismo_kpis_single_query_returns_correct_values(): void
    {
        $fuente = $this->createFuente();
        $organismo = $this->createOrganismo();

        $adj1 = Adjudicatario::create(['nif' => 'B11111111', 'nombre' => 'Empresa A']);
        $adj2 = Adjudicatario::create(['nif' => 'B22222222', 'nombre' => 'Empresa B']);

        // 3 contratos: 2 menores, importes 100, 200, 300
        $this->createContrato($organismo->id, $adj1->id, $fuente->id, 100.00, true, 1);
        $this->createContrato($organismo->id, $adj1->id, $fuente->id, 200.00, true, 2);
        $this->createContrato($organismo->id, $adj2->id, $fuente->id, 300.00, false, 3);

        $response = $this->get("/organismos/{$organismo->nif}");
        $response->assertStatus(200);

        $ficha = $response->viewData('ficha');

        $this->assertSame(3, $ficha['kpis']['total_contratos']);
        $this->assertEquals(600.00, (float) $ficha['kpis']['importe_total']);
        $this->assertSame(2, $ficha['kpis']['adjudicatarios_distintos']);
        // 2 menores out of 3 = 66.7%
        $this->assertEquals(66.7, $ficha['kpis']['pct_menores']);
    }

    public function test_organismo_kpis_zero_contratos_pct_menores_is_zero(): void
    {
        $organismo = $this->createOrganismo();

        $response = $this->get("/organismos/{$organismo->nif}");
        $response->assertStatus(200);

        $ficha = $response->viewData('ficha');

        $this->assertSame(0, $ficha['kpis']['total_contratos']);
        $this->assertSame(0, $ficha['kpis']['pct_menores']);
    }

    public function test_organismo_kpis_all_menores(): void
    {
        $fuente = $this->createFuente();
        $organismo = $this->createOrganismo();
        $adj = Adjudicatario::create(['nif' => 'B33333333', 'nombre' => 'Empresa C']);

        $this->createContrato($organismo->id, $adj->id, $fuente->id, 50.00, true, 10);
        $this->createContrato($organismo->id, $adj->id, $fuente->id, 50.00, true, 11);

        $response = $this->get("/organismos/{$organismo->nif}");
        $response->assertStatus(200);

        $ficha = $response->viewData('ficha');
        $this->assertEquals(100.0, $ficha['kpis']['pct_menores']);
    }

    // --- EmpresaController tests ---

    public function test_empresa_kpis_single_query_returns_correct_values(): void
    {
        $fuente = $this->createFuente();
        $adjudicatario = $this->createAdjudicatario();

        $org1 = Organismo::create(['nif' => 'P0000002B', 'nombre' => 'Organismo A']);
        $org2 = Organismo::create(['nif' => 'P0000003C', 'nombre' => 'Organismo B']);

        // 3 contratos: importes 100, 500, 200 desde 2 organismos distintos
        $this->createContrato($org1->id, $adjudicatario->id, $fuente->id, 100.00, false, 20);
        $this->createContrato($org1->id, $adjudicatario->id, $fuente->id, 500.00, false, 21);
        $this->createContrato($org2->id, $adjudicatario->id, $fuente->id, 200.00, false, 22);

        $response = $this->get("/empresas/{$adjudicatario->nif}");
        $response->assertStatus(200);

        $ficha = $response->viewData('ficha');

        $this->assertSame(3, $ficha['kpis']['total_contratos']);
        $this->assertEquals(800.00, (float) $ficha['kpis']['importe_total']);
        $this->assertSame(2, $ficha['kpis']['organismos_distintos']);
        $this->assertEquals(500.00, (float) $ficha['kpis']['contrato_mayor']);
    }

    public function test_empresa_kpis_zero_contratos(): void
    {
        $adjudicatario = $this->createAdjudicatario();

        $response = $this->get("/empresas/{$adjudicatario->nif}");
        $response->assertStatus(200);

        $ficha = $response->viewData('ficha');

        $this->assertSame(0, $ficha['kpis']['total_contratos']);
        $this->assertNull($ficha['kpis']['contrato_mayor']);
    }
}
