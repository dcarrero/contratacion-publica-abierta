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

    public function test_ficha_calcula_concentracion_hhi_y_sin_concurrencia(): void
    {
        $organismo = Organismo::create(['nif' => 'P4502000B', 'nombre' => 'Diputación Test HHI']);
        $a = Adjudicatario::create(['nif' => 'B02100001', 'nombre' => 'Proveedor A SL']);
        $b = Adjudicatario::create(['nif' => 'B02100002', 'nombre' => 'Proveedor B SL']);
        $fuente = $this->createFuente();

        $mk = function (int $adjId, float $importe, ?int $ofertas) use ($organismo, $fuente) {
            static $n = 0;
            $n++;
            Contrato::create([
                'placsp_id' => 'ORG-HHI-'.$n,
                'url_placsp' => 'https://example.com/h'.$n,
                'organismo_id' => $organismo->id,
                'adjudicatario_id' => $adjId,
                'importe_adjudicacion' => $importe,
                'num_ofertas' => $ofertas,
                'fuente_datos_id' => $fuente->id,
                'version' => 1,
            ]);
        };

        // A: 80.000 (1 oferta) · B: 20.000 (3 ofertas) → shares 0,8/0,2 → HHI=6800 (Alta); sin conc.=50%
        $mk($a->id, 80000, 1);
        $mk($b->id, 20000, 3);

        $ficha = $this->get("/organismos/{$organismo->nif}")->assertStatus(200)->viewData('ficha');

        $this->assertSame(6800, $ficha['kpis']['concentracion_hhi']);
        $this->assertSame('Alta', $ficha['kpis']['concentracion_label']);
        $this->assertEqualsWithDelta(50.0, $ficha['kpis']['pct_sin_concurrencia'], 0.1);
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
