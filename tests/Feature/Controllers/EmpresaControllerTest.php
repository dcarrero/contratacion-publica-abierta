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
 * binding por nif, la ficha centrada en un año y la página de histórico.
 *
 * Limitación SQLite vs PostgreSQL: suite en SQLite :memory:; los scopes
 * scopeSearch con FTS/tsvector (PG-only) no se prueban aquí.
 */
class EmpresaControllerTest extends TestCase
{
    use RefreshDatabase;

    private ?FuenteDatos $fuente = null;

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

    private function createContrato(Adjudicatario $adjudicatario, Organismo $organismo, string $placspId, ?string $fecha, float $importe = 12000.00): Contrato
    {
        // Propiedad de instancia, no static: RefreshDatabase vacía la tabla entre tests.
        $this->fuente ??= $this->createFuente();

        return Contrato::create([
            'placsp_id' => $placspId,
            'url_placsp' => 'https://example.com/'.$placspId,
            'organismo_id' => $organismo->id,
            'adjudicatario_id' => $adjudicatario->id,
            'fecha_publicacion' => $fecha,
            'importe_adjudicacion' => $importe,
            'fuente_datos_id' => $this->fuente->id,
            'version' => 1,
        ]);
    }

    private function createAdjudicatario(string $nif = 'B28111111'): Adjudicatario
    {
        return Adjudicatario::create(['nif' => $nif, 'nombre' => 'Empresa '.$nif]);
    }

    private function createOrganismo(string $nif = 'P2800001A'): Organismo
    {
        return Organismo::create(['nif' => $nif, 'nombre' => 'Organismo '.$nif]);
    }

    // ----------------------------------------------------------------
    // Ficha
    // ----------------------------------------------------------------

    public function test_show_empresa_con_contratos_devuelve_200(): void
    {
        $adjudicatario = $this->createAdjudicatario();
        $organismo = $this->createOrganismo();
        $this->createContrato($adjudicatario, $organismo, 'EMP-CTRL-001', date('Y').'-01-15');

        $response = $this->get("/empresas/{$adjudicatario->nif}");

        $response->assertStatus(200);
        $response->assertViewIs('empresas.show');
        $response->assertViewHas('empresa');
        $response->assertViewHas('ficha');
        $response->assertViewHas('serie');
        $response->assertViewHas('comparativa');
    }

    public function test_show_centra_la_ficha_en_el_anio_en_curso(): void
    {
        $anio = (int) date('Y');
        $adjudicatario = $this->createAdjudicatario();
        $organismo = $this->createOrganismo();

        $this->createContrato($adjudicatario, $organismo, 'EMP-ACT-1', $anio.'-01-15', 1000.00);
        $this->createContrato($adjudicatario, $organismo, 'EMP-ANT-1', ($anio - 1).'-01-15', 9999.00);
        $this->createContrato($adjudicatario, $organismo, 'EMP-ANT-2', ($anio - 1).'-06-15', 9999.00);

        $response = $this->get("/empresas/{$adjudicatario->nif}");

        $response->assertStatus(200);
        $this->assertSame($anio, $response->viewData('anio'));

        // Sólo el contrato del año en curso entra en los KPIs de la ficha.
        $this->assertSame(1, $response->viewData('ficha')['kpis']['contratos']);
        $this->assertSame(1000.0, $response->viewData('ficha')['kpis']['importe']);
    }

    public function test_show_cae_al_ultimo_anio_con_datos_si_no_hay_actividad_reciente(): void
    {
        $adjudicatario = $this->createAdjudicatario();
        $organismo = $this->createOrganismo();
        $this->createContrato($adjudicatario, $organismo, 'EMP-VIEJO-1', '2019-04-10', 5000.00);

        $response = $this->get("/empresas/{$adjudicatario->nif}");

        $response->assertStatus(200);
        $this->assertSame(2019, $response->viewData('anio'));
        $this->assertTrue($response->viewData('serie')->inactivaEn((int) date('Y')));

        // La vista avisa y ofrece el histórico de forma destacada.
        $response->assertSee('Sin actividad registrada en '.date('Y'));
        $response->assertSee(route('empresas.historico', $adjudicatario->nif));
    }

    public function test_show_con_anio_en_la_url_fija_ese_anio(): void
    {
        $adjudicatario = $this->createAdjudicatario();
        $organismo = $this->createOrganismo();
        $this->createContrato($adjudicatario, $organismo, 'EMP-2021-1', '2021-03-01', 4000.00);
        $this->createContrato($adjudicatario, $organismo, 'EMP-2022-1', '2022-03-01', 7000.00);

        $response = $this->get("/empresas/{$adjudicatario->nif}/2021");

        $response->assertStatus(200);
        $this->assertSame(2021, $response->viewData('anio'));
        $this->assertTrue($response->viewData('anioFijado'));
        $this->assertSame(4000.0, $response->viewData('ficha')['kpis']['importe']);
    }

    public function test_show_con_anio_fuera_de_rango_redirige_a_la_ficha(): void
    {
        $adjudicatario = $this->createAdjudicatario();

        $this->get("/empresas/{$adjudicatario->nif}/1999")
            ->assertStatus(301)
            ->assertRedirect(route('empresas.show', $adjudicatario->nif));

        $this->get('/empresas/'.$adjudicatario->nif.'/'.(date('Y') + 5))
            ->assertStatus(301);
    }

    public function test_show_empresa_sin_contratos_devuelve_200_sin_error(): void
    {
        $adjudicatario = $this->createAdjudicatario('B28222222');

        $response = $this->get("/empresas/{$adjudicatario->nif}");

        $response->assertStatus(200);
        $response->assertViewIs('empresas.show');
        $this->assertSame(0, $response->viewData('ficha')['kpis']['contratos']);
        $this->assertTrue($response->viewData('serie')->estaVacia());
    }

    public function test_show_empresa_solo_con_contratos_sin_fecha(): void
    {
        $adjudicatario = $this->createAdjudicatario('B28333333');
        $organismo = $this->createOrganismo();
        $this->createContrato($adjudicatario, $organismo, 'EMP-SINFECHA-1', null, 3000.00);

        $response = $this->get("/empresas/{$adjudicatario->nif}");

        $response->assertStatus(200);
        $this->assertTrue($response->viewData('serie')->estaVacia());
        $this->assertSame(1, $response->viewData('serie')->sinFecha);
        $response->assertSee('Sin contratos con fecha de publicación');
    }

    public function test_show_empresa_inexistente_devuelve_404(): void
    {
        $this->get('/empresas/X99999999')->assertStatus(404);
    }

    public function test_la_vista_de_anio_duplicada_canonicaliza_a_la_ficha(): void
    {
        $adjudicatario = $this->createAdjudicatario();
        $organismo = $this->createOrganismo();
        $this->createContrato($adjudicatario, $organismo, 'EMP-CANON-1', '2019-04-10', 5000.00);
        $this->createContrato($adjudicatario, $organismo, 'EMP-CANON-2', '2018-04-10', 2000.00);

        // 2019 es el año que la ficha muestra por defecto (no hay actividad reciente):
        // /empresas/{nif}/2019 es la misma página, así que apunta a la ficha.
        $this->get("/empresas/{$adjudicatario->nif}/2019")
            ->assertStatus(200)
            ->assertSee('<link rel="canonical" href="'.route('empresas.show', $adjudicatario->nif).'">', false);

        // 2018 es contenido propio: canónica a sí misma.
        $this->get("/empresas/{$adjudicatario->nif}/2018")
            ->assertStatus(200)
            ->assertSee('<link rel="canonical" href="'.route('empresas.anio', [$adjudicatario->nif, 2018]).'">', false);
    }

    // ----------------------------------------------------------------
    // Histórico
    // ----------------------------------------------------------------

    public function test_historico_devuelve_200_con_todos_los_anios(): void
    {
        $adjudicatario = $this->createAdjudicatario();
        $organismo = $this->createOrganismo();
        $this->createContrato($adjudicatario, $organismo, 'EMP-H-2021', '2021-05-01', 1000.00);
        $this->createContrato($adjudicatario, $organismo, 'EMP-H-2022', '2022-05-01', 2000.00);
        $this->createContrato($adjudicatario, $organismo, 'EMP-H-2023', '2023-05-01', 3000.00);

        $response = $this->get("/empresas/{$adjudicatario->nif}/historico");

        $response->assertStatus(200);
        $response->assertViewIs('empresas.historico');

        $anios = $response->viewData('anios');
        $this->assertCount(3, $anios);
        $this->assertSame(2023, $anios[0]['anio']);

        // La matriz año x mes cubre los tres años.
        $this->assertCount(3, $response->viewData('matriz')['filas']);

        // El comparador arranca con los dos años más recientes.
        $this->assertSame([2023, 2022], array_keys($response->viewData('seriesComparadas')));
    }

    public function test_historico_de_empresa_sin_datos_no_rompe(): void
    {
        $adjudicatario = $this->createAdjudicatario('B28444444');

        $response = $this->get("/empresas/{$adjudicatario->nif}/historico");

        $response->assertStatus(200);
        $response->assertSee('Sin datos históricos');
    }

    public function test_historico_enlaza_a_las_vistas_de_cada_anio(): void
    {
        $adjudicatario = $this->createAdjudicatario();
        $organismo = $this->createOrganismo();
        $this->createContrato($adjudicatario, $organismo, 'EMP-H2-2020', '2020-05-01', 1000.00);

        $this->get("/empresas/{$adjudicatario->nif}/historico")
            ->assertStatus(200)
            ->assertSee(route('empresas.anio', [$adjudicatario->nif, 2020]));
    }

    // ----------------------------------------------------------------
    // Red de relaciones
    // ----------------------------------------------------------------

    public function test_red_devuelve_200_y_ofrece_las_pestanas(): void
    {
        $anio = (int) date('Y');
        $adjudicatario = $this->createAdjudicatario();
        $organismo = $this->createOrganismo();
        $this->createContrato($adjudicatario, $organismo, 'EMP-RED-1', $anio.'-03-01', 1000.00);

        $response = $this->get("/empresas/{$adjudicatario->nif}/red");

        $response->assertStatus(200);
        $response->assertViewIs('empresas.red');
        $response->assertViewHas('red');
        $response->assertSee(route('empresas.show', $adjudicatario->nif));
        $response->assertSee(route('empresas.historico', $adjudicatario->nif));
    }

    public function test_red_sin_relaciones_recientes_lo_explica(): void
    {
        $adjudicatario = $this->createAdjudicatario('B28555555');
        $organismo = $this->createOrganismo();
        $this->createContrato($adjudicatario, $organismo, 'EMP-RED-VIEJO', '2015-03-01', 1000.00);

        // El año de referencia es 2015, así que la ventana lo incluye y hay red.
        $response = $this->get("/empresas/{$adjudicatario->nif}/red");

        $response->assertStatus(200);
        $this->assertSame(2013, $response->viewData('red')['meta']['anio_desde']);
        $this->assertSame(2015, $response->viewData('red')['meta']['anio_hasta']);
    }

    public function test_la_ficha_enlaza_a_la_red(): void
    {
        $adjudicatario = $this->createAdjudicatario();
        $organismo = $this->createOrganismo();
        $this->createContrato($adjudicatario, $organismo, 'EMP-TAB-1', date('Y').'-03-01', 1000.00);

        $this->get("/empresas/{$adjudicatario->nif}")
            ->assertStatus(200)
            ->assertSee(route('empresas.red', $adjudicatario->nif));
    }

    public function test_index_empresas_devuelve_200(): void
    {
        $this->get('/empresas')->assertStatus(200);
    }
}
