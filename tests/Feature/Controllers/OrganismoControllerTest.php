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
 * Tests de routing y respuesta HTTP para OrganismoController.
 *
 * KPIs ya cubiertos en KpisControllerTest; aquí verificamos routing,
 * binding por nif y edge cases de vista.
 *
 * Limitación SQLite vs PostgreSQL: suite en SQLite :memory:; los scopes
 * scopeSearch con FTS/tsvector (PG-only) no se prueban aquí.
 */
class OrganismoControllerTest extends TestCase
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

    public function test_show_organismo_con_contratos_devuelve_200(): void
    {
        $organismo = Organismo::create([
            'nif' => 'P4501000A',
            'nombre' => 'Diputación de Albacete',
        ]);

        $adjudicatario = Adjudicatario::create([
            'nif' => 'B02111111',
            'nombre' => 'Empresa Albacete S.L.',
        ]);

        $fuente = $this->createFuente();

        Contrato::create([
            'placsp_id' => 'ORG-CTRL-001',
            'url_placsp' => 'https://example.com/1',
            'organismo_id' => $organismo->id,
            'adjudicatario_id' => $adjudicatario->id,
            'importe_adjudicacion' => 5000.00,
            'fuente_datos_id' => $fuente->id,
            'version' => 1,
        ]);

        $response = $this->get("/organismos/{$organismo->nif}");

        $response->assertStatus(200);
        $response->assertViewIs('organismos.show');
        $response->assertViewHas('organismo');
        $response->assertViewHas('ficha');
    }

    public function test_show_organismo_sin_contratos_devuelve_200_sin_error(): void
    {
        $organismo = Organismo::create([
            'nif' => 'P4502000B',
            'nombre' => 'Organismo sin contratos',
        ]);

        $response = $this->get("/organismos/{$organismo->nif}");

        $response->assertStatus(200);
        $response->assertViewIs('organismos.show');

        $ficha = $response->viewData('ficha');
        $this->assertSame(0, $ficha['kpis']['total_contratos']);
    }

    public function test_show_organismo_inexistente_devuelve_404(): void
    {
        $response = $this->get('/organismos/P9999999X');

        $response->assertStatus(404);
    }

    public function test_index_organismos_devuelve_200(): void
    {
        $response = $this->get('/organismos');

        $response->assertStatus(200);
    }
}
