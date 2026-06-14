<?php

declare(strict_types=1);

namespace Tests\Feature\Controllers;

use App\Models\Adjudicatario;
use App\Models\Contrato;
use App\Models\FuenteDatos;
use App\Models\Organismo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests de routing y respuesta HTTP para EmpresaController.
 *
 * KPIs ya cubiertos en KpisControllerTest; aquí verificamos routing,
 * binding por nif y edge cases de vista.
 *
 * Limitación SQLite vs PostgreSQL: suite en SQLite :memory:; los scopes
 * scopeSearch con FTS/tsvector (PG-only) no se prueban aquí.
 */
class EmpresaControllerTest extends TestCase
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
            'slug' => 'test-fuente-emp',
            'tipo' => 'atom',
            'activo' => true,
        ]);
    }

    public function test_show_empresa_con_contratos_devuelve_200(): void
    {
        $adjudicatario = Adjudicatario::create([
            'nif' => 'B28111111',
            'nombre' => 'Empresa Madrid S.A.',
        ]);

        $organismo = Organismo::create([
            'nif' => 'P2800001A',
            'nombre' => 'Ayuntamiento de Madrid',
        ]);

        $fuente = $this->createFuente();

        Contrato::create([
            'placsp_id' => 'EMP-CTRL-001',
            'url_placsp' => 'https://example.com/emp1',
            'organismo_id' => $organismo->id,
            'adjudicatario_id' => $adjudicatario->id,
            'importe_adjudicacion' => 12000.00,
            'fuente_datos_id' => $fuente->id,
            'version' => 1,
        ]);

        $response = $this->get("/empresas/{$adjudicatario->nif}");

        $response->assertStatus(200);
        $response->assertViewIs('empresas.show');
        $response->assertViewHas('empresa');
        $response->assertViewHas('ficha');
    }

    public function test_show_empresa_sin_contratos_devuelve_200_sin_error(): void
    {
        $adjudicatario = Adjudicatario::create([
            'nif' => 'B28222222',
            'nombre' => 'Empresa sin contratos S.L.',
        ]);

        $response = $this->get("/empresas/{$adjudicatario->nif}");

        $response->assertStatus(200);
        $response->assertViewIs('empresas.show');

        $ficha = $response->viewData('ficha');
        $this->assertSame(0, $ficha['kpis']['total_contratos']);
    }

    public function test_show_empresa_inexistente_devuelve_404(): void
    {
        $response = $this->get('/empresas/X99999999');

        $response->assertStatus(404);
    }

    public function test_index_empresas_devuelve_200(): void
    {
        $response = $this->get('/empresas');

        $response->assertStatus(200);
    }
}
