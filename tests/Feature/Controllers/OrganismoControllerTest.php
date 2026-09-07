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
            'fecha_publicacion' => date('Y').'-02-10',
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
                'fecha_publicacion' => date('Y').'-02-10',
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
        $this->assertSame(0, $ficha['kpis']['contratos']);
    }

    public function test_show_organismo_inexistente_devuelve_404(): void
    {
        $response = $this->get('/organismos/P9999999X');

        $response->assertStatus(404);
    }

    // ----------------------------------------------------------------
    // Ficha por año e histórico
    // ----------------------------------------------------------------

    public function test_show_centra_la_ficha_en_el_anio_en_curso(): void
    {
        $anio = (int) date('Y');
        $organismo = Organismo::create(['nif' => 'P4503000C', 'nombre' => 'Organismo anual']);
        $adj = Adjudicatario::create(['nif' => 'B02100003', 'nombre' => 'Proveedor C SL']);
        $fuente = $this->createFuente();

        $mk = function (string $fecha, float $importe) use ($organismo, $adj, $fuente) {
            static $n = 0;
            $n++;
            Contrato::create([
                'placsp_id' => 'ORG-ANIO-'.$n,
                'url_placsp' => 'https://example.com/a'.$n,
                'organismo_id' => $organismo->id,
                'adjudicatario_id' => $adj->id,
                'fecha_publicacion' => $fecha,
                'importe_adjudicacion' => $importe,
                'fuente_datos_id' => $fuente->id,
                'version' => 1,
            ]);
        };

        $mk($anio.'-02-01', 1000.00);
        $mk(($anio - 1).'-02-01', 9999.00);

        $response = $this->get("/organismos/{$organismo->nif}");

        $response->assertStatus(200);
        $this->assertSame($anio, $response->viewData('anio'));
        $this->assertSame(1000.0, $response->viewData('ficha')['kpis']['importe']);

        // Y el año concreto en la URL fija ese año.
        $anterior = $this->get('/organismos/'.$organismo->nif.'/'.($anio - 1));
        $anterior->assertStatus(200);
        $this->assertSame(9999.0, $anterior->viewData('ficha')['kpis']['importe']);
    }

    public function test_show_avisa_cuando_el_organismo_no_tiene_actividad_reciente(): void
    {
        $organismo = Organismo::create(['nif' => 'P4504000D', 'nombre' => 'Organismo inactivo']);
        $fuente = $this->createFuente();

        Contrato::create([
            'placsp_id' => 'ORG-VIEJO-1',
            'url_placsp' => 'https://example.com/v1',
            'organismo_id' => $organismo->id,
            'fecha_publicacion' => '2018-06-01',
            'importe_adjudicacion' => 700.00,
            'fuente_datos_id' => $fuente->id,
            'version' => 1,
        ]);

        $response = $this->get("/organismos/{$organismo->nif}");

        $response->assertStatus(200);
        $this->assertSame(2018, $response->viewData('anio'));
        $response->assertSee('Sin actividad registrada en '.date('Y'));
        $response->assertSee(route('organismos.historico', $organismo->nif));
    }

    public function test_show_con_anio_fuera_de_rango_redirige(): void
    {
        $organismo = Organismo::create(['nif' => 'P4505000E', 'nombre' => 'Organismo rango']);

        $this->get("/organismos/{$organismo->nif}/1999")
            ->assertStatus(301)
            ->assertRedirect(route('organismos.show', $organismo->nif));
    }

    public function test_historico_devuelve_200(): void
    {
        $organismo = Organismo::create(['nif' => 'P4506000F', 'nombre' => 'Organismo histórico']);
        $fuente = $this->createFuente();

        foreach (['2021-03-01' => 1000.00, '2022-03-01' => 2000.00] as $fecha => $importe) {
            Contrato::create([
                'placsp_id' => 'ORG-H-'.$fecha,
                'url_placsp' => 'https://example.com/h'.$fecha,
                'organismo_id' => $organismo->id,
                'fecha_publicacion' => $fecha,
                'importe_adjudicacion' => $importe,
                'fuente_datos_id' => $fuente->id,
                'version' => 1,
            ]);
        }

        $response = $this->get("/organismos/{$organismo->nif}/historico");

        $response->assertStatus(200);
        $response->assertViewIs('organismos.historico');
        $this->assertCount(2, $response->viewData('anios'));
        $this->assertSame(2022, $response->viewData('anios')[0]['anio']);

        // El contrato mayor se resuelve por el máximo de la ficha, no por orden global.
        $this->assertSame(2000.0, (float) $response->viewData('contratoMayor')->importe_adjudicacion);
    }

    public function test_red_devuelve_200(): void
    {
        $anio = (int) date('Y');
        $organismo = Organismo::create(['nif' => 'P4507000G', 'nombre' => 'Organismo red']);
        $adj = Adjudicatario::create(['nif' => 'B02100009', 'nombre' => 'Proveedor red SL']);
        $fuente = $this->createFuente();

        Contrato::create([
            'placsp_id' => 'ORG-RED-1',
            'url_placsp' => 'https://example.com/red1',
            'organismo_id' => $organismo->id,
            'adjudicatario_id' => $adj->id,
            'fecha_publicacion' => $anio.'-03-01',
            'importe_adjudicacion' => 1000.00,
            'fuente_datos_id' => $fuente->id,
            'version' => 1,
        ]);

        $response = $this->get("/organismos/{$organismo->nif}/red");

        $response->assertStatus(200);
        $response->assertViewIs('organismos.red');
        $this->assertSame('organismo', $response->viewData('red')['meta']['tipo_centro']);
        $response->assertSee(route('organismos.historico', $organismo->nif));
    }

    public function test_index_organismos_devuelve_200(): void
    {
        $response = $this->get('/organismos');

        $response->assertStatus(200);
    }
}
