<?php

declare(strict_types=1);

namespace Tests\Feature\Controllers;

use App\Models\Contrato;
use App\Models\FuenteDatos;
use App\Models\Organismo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Ficha de comunidad autónoma centrada en un año, página de histórico y uso de las
 * estadísticas precomputadas por `stats:recalculate`.
 */
class AdministracionControllerTest extends TestCase
{
    use RefreshDatabase;

    private ?FuenteDatos $fuente = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->withoutVite();
        Storage::fake('local');
    }

    private function createContrato(string $nuts, ?string $fecha, float $importe, ?Organismo $organismo = null): Contrato
    {
        static $seq = 0;
        $seq++;

        $this->fuente ??= FuenteDatos::where('slug', 'bquant-bootstrap')->firstOrFail();
        $organismo ??= Organismo::firstOrCreate(
            ['nif' => 'Q1600001A'],
            ['nombre' => 'Junta CLM', 'nombre_normalizado' => 'JUNTA CLM']
        );

        return Contrato::create([
            'placsp_id' => 'ADM-'.$seq,
            'url_placsp' => 'https://example.com/adm'.$seq,
            'organismo_id' => $organismo->id,
            'nuts' => $nuts,
            'fecha_publicacion' => $fecha,
            'importe_adjudicacion' => $importe,
            'fuente_datos_id' => $this->fuente->id,
            'version' => 1,
        ]);
    }

    // ----------------------------------------------------------------
    // Ficha
    // ----------------------------------------------------------------

    public function test_show_centra_la_ficha_en_el_anio_en_curso(): void
    {
        $anio = (int) date('Y');

        $this->createContrato('ES425', $anio.'-03-01', 1000.00);
        $this->createContrato('ES421', ($anio - 1).'-03-01', 9999.00);

        $response = $this->get('/administraciones/ES42');

        $response->assertStatus(200);
        $response->assertViewIs('administraciones.show');
        $this->assertSame($anio, $response->viewData('anio'));
        $this->assertSame(1000.0, $response->viewData('ficha')['kpis']['importe']);
    }

    public function test_show_no_mezcla_contratos_de_otras_comunidades(): void
    {
        $anio = (int) date('Y');

        $this->createContrato('ES425', $anio.'-03-01', 1000.00);
        $this->createContrato('ES300', $anio.'-03-01', 5000.00);

        $this->assertSame(
            1000.0,
            $this->get('/administraciones/ES42')->viewData('ficha')['kpis']['importe']
        );
        $this->assertSame(
            5000.0,
            $this->get('/administraciones/ES30')->viewData('ficha')['kpis']['importe']
        );
    }

    public function test_show_con_anio_en_la_url_fija_ese_anio(): void
    {
        $this->createContrato('ES425', '2022-05-01', 400.00);
        $this->createContrato('ES425', '2023-05-01', 700.00);

        $response = $this->get('/administraciones/ES42/2022');

        $response->assertStatus(200);
        $this->assertSame(2022, $response->viewData('anio'));
        $this->assertTrue($response->viewData('anioFijado'));
        $this->assertSame(400.0, $response->viewData('ficha')['kpis']['importe']);
    }

    public function test_show_con_anio_fuera_de_rango_redirige(): void
    {
        $this->get('/administraciones/ES42/1999')
            ->assertStatus(301)
            ->assertRedirect(route('administraciones.show', 'ES42'));
    }

    public function test_show_avisa_cuando_no_hay_actividad_en_el_anio_en_curso(): void
    {
        $this->createContrato('ES425', '2019-07-01', 300.00);

        $response = $this->get('/administraciones/ES42');

        $response->assertStatus(200);
        $this->assertSame(2019, $response->viewData('anio'));
        $response->assertSee('Sin actividad registrada en '.date('Y'));
        $response->assertSee(route('administraciones.historico', 'ES42'));
    }

    public function test_show_sin_stats_precomputadas_no_finge_ceros(): void
    {
        $this->createContrato('ES425', date('Y').'-03-01', 1000.00);

        $response = $this->get('/administraciones/ES42');

        $response->assertStatus(200);
        $this->assertFalse($response->viewData('stats_precomputadas'));
        $this->assertNull($response->viewData('total_organismos'));
        $this->assertNull($response->viewData('total_adjudicatarios'));
        $response->assertSee('todavía no están disponibles', false);
    }

    // ----------------------------------------------------------------
    // Serie precomputada por stats:recalculate
    // ----------------------------------------------------------------

    public function test_stats_recalculate_escribe_la_serie_mensual_de_la_comunidad(): void
    {
        $this->createContrato('ES425', '2024-03-05', 1000.00);
        $this->createContrato('ES421', '2024-03-20', 500.00);
        $this->createContrato('ES425', '2025-11-01', 2000.00);
        $this->createContrato('ES425', null, 777.00);
        $this->createContrato('ES300', '2024-03-05', 9999.00);

        $this->artisan('stats:recalculate', ['--entity' => 'mapa'])->assertSuccessful();

        Storage::assertExists('mapa-stats/admin-ES42.json');
        $datos = json_decode(Storage::get('mapa-stats/admin-ES42.json'), true);

        // Marzo de 2024 agrega los dos NUTS de la comunidad; Madrid queda fuera.
        // assertEquals y no assertSame: al serializar a JSON, 2000.0 se guarda como 2000
        // y vuelve como int; SerieTemporal lo castea a float igualmente.
        $this->assertEquals(
            [
                ['anio' => 2024, 'mes' => 3, 'num' => 2, 'importe' => 1500.0],
                ['anio' => 2025, 'mes' => 11, 'num' => 1, 'importe' => 2000.0],
            ],
            $datos['serie_mensual']
        );

        // El contrato sin fecha se cuenta aparte, no se pierde ni ensucia la serie.
        $this->assertSame(1, $datos['serie_sin_fecha']);
    }

    public function test_la_ficha_usa_la_serie_precomputada_cuando_existe(): void
    {
        $this->createContrato('ES425', '2024-03-05', 1000.00);
        $this->artisan('stats:recalculate', ['--entity' => 'mapa'])->assertSuccessful();

        $response = $this->get('/administraciones/ES42');

        $response->assertStatus(200);
        $this->assertSame(['num' => 1, 'importe' => 1000.0], $response->viewData('serie')->totales());
        $this->assertTrue($response->viewData('stats_precomputadas'));
        $this->assertNotNull($response->viewData('total_organismos'));
    }

    // ----------------------------------------------------------------
    // Histórico
    // ----------------------------------------------------------------

    public function test_historico_devuelve_200_con_todos_los_anios(): void
    {
        $this->createContrato('ES425', '2021-04-01', 1000.00);
        $this->createContrato('ES425', '2022-04-01', 3000.00);

        $response = $this->get('/administraciones/ES42/historico');

        $response->assertStatus(200);
        $response->assertViewIs('administraciones.historico');

        $anios = $response->viewData('anios');
        $this->assertCount(2, $anios);
        $this->assertSame(2022, $anios[0]['anio']);
        $this->assertCount(2, $response->viewData('matriz')['filas']);
        $response->assertSee(route('administraciones.anio', ['ES42', 2021]));
    }

    public function test_historico_sin_datos_no_rompe(): void
    {
        $this->get('/administraciones/ES42/historico')
            ->assertStatus(200)
            ->assertSee('Sin datos históricos');
    }

    public function test_index_devuelve_200(): void
    {
        $this->get('/administraciones')->assertStatus(200);
    }
}
