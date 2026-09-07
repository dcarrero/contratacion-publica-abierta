<?php

declare(strict_types=1);

namespace Tests\Feature\Controllers;

use App\Models\Adjudicatario;
use App\Models\Contrato;
use App\Models\FuenteDatos;
use App\Models\Organismo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Tests para DashboardController (ruta GET /).
 *
 * Verifica que el dashboard responde 200 tanto con BD vacía (sin datos)
 * como con datos de prueba. Los KPIs del dashboard se calculan a partir de
 * totales en los modelos Organismo/Adjudicatario.
 *
 * Limitación SQLite vs PostgreSQL: suite en SQLite :memory:.
 */
class DashboardControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        // La portada lee la serie nacional y los rankings que deja `stats:recalculate`:
        // sin el fake, los tests leerían los JSON reales del entorno de desarrollo.
        Storage::fake('local');
    }

    public function test_dashboard_sin_datos_devuelve_200(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertViewIs('dashboard');
    }

    public function test_dashboard_con_datos_devuelve_200_y_kpis(): void
    {
        $fuente = FuenteDatos::create([
            'nombre' => 'Test Fuente',
            'slug' => 'test-fuente-dash',
            'tipo' => 'atom',
            'activo' => true,
        ]);

        $organismo = Organismo::create([
            'nif' => 'P0000010A',
            'nombre' => 'Organismo Dashboard',
            'total_contratos' => 5,
            'total_importe' => 50000.00,
        ]);

        $adjudicatario = Adjudicatario::create([
            'nif' => 'B10000001',
            'nombre' => 'Empresa Dashboard S.L.',
            'total_contratos' => 5,
            'total_importe' => 50000.00,
        ]);

        Contrato::create([
            'placsp_id' => 'DASH-001',
            'url_placsp' => 'https://example.com/dash1',
            'organismo_id' => $organismo->id,
            'adjudicatario_id' => $adjudicatario->id,
            'importe_adjudicacion' => 10000.00,
            'fuente_datos_id' => $fuente->id,
            'version' => 1,
            'fecha_publicacion' => '2024-01-15',
            'objeto' => 'Servicio de mantenimiento',
        ]);

        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertViewHas('serie');
        $response->assertViewHas('comparativa');
        $response->assertViewHas('topEmpresas');
        $response->assertViewHas('topOrganismos');
        $response->assertViewHas('ultimosContratos');

        // La portada titula con el año mostrado; los acumulados pasan a ser contexto.
        $this->assertSame(2024, $response->viewData('anio'));
        $this->assertSame(['num' => 1, 'importe' => 10000.0], $response->viewData('totalAnio'));

        $totales = $response->viewData('totalesHistoricos');
        $this->assertSame(5, $totales['contratos']);
        $this->assertSame(1, $totales['organismos']);
        $this->assertSame(1, $totales['adjudicatarios']);
    }

    public function test_dashboard_muestra_el_anio_en_curso_con_comparativa(): void
    {
        $anio = (int) date('Y');

        $fuente = FuenteDatos::create([
            'nombre' => 'Test Fuente YTD',
            'slug' => 'test-fuente-ytd',
            'tipo' => 'atom',
            'activo' => true,
        ]);
        $organismo = Organismo::create(['nif' => 'P0000012C', 'nombre' => 'Organismo YTD']);

        foreach ([[$anio.'-01-10', 1000.0], [($anio - 1).'-01-10', 500.0]] as $i => [$fecha, $importe]) {
            Contrato::create([
                'placsp_id' => 'DASH-YTD-'.$i,
                'url_placsp' => 'https://example.com/ytd'.$i,
                'organismo_id' => $organismo->id,
                'fuente_datos_id' => $fuente->id,
                'version' => 1,
                'fecha_publicacion' => $fecha,
                'importe_adjudicacion' => $importe,
            ]);
        }

        $response = $this->get('/');

        $response->assertStatus(200);
        $this->assertSame($anio, $response->viewData('anio'));
        $this->assertSame(['num' => 1, 'importe' => 1000.0], $response->viewData('totalAnio'));

        // Enero está cerrado salvo en enero, cuando la comparativa cae al año anterior.
        $comparativa = $response->viewData('comparativa');
        $this->assertContains($comparativa['anio'], [$anio, $anio - 1]);
    }

    public function test_historico_nacional_devuelve_200(): void
    {
        $fuente = FuenteDatos::create([
            'nombre' => 'Test Fuente Hist',
            'slug' => 'test-fuente-hist',
            'tipo' => 'atom',
            'activo' => true,
        ]);
        $organismo = Organismo::create(['nif' => 'P0000013D', 'nombre' => 'Organismo Hist']);

        foreach (['2021-02-01' => 100.0, '2022-02-01' => 300.0] as $fecha => $importe) {
            Contrato::create([
                'placsp_id' => 'DASH-H-'.$fecha,
                'url_placsp' => 'https://example.com/h'.$fecha,
                'organismo_id' => $organismo->id,
                'fuente_datos_id' => $fuente->id,
                'version' => 1,
                'fecha_publicacion' => $fecha,
                'importe_adjudicacion' => $importe,
            ]);
        }

        $response = $this->get('/historico');

        $response->assertStatus(200);
        $response->assertViewIs('historico');
        $this->assertCount(2, $response->viewData('anios'));
        $this->assertSame(2022, $response->viewData('anios')[0]['anio']);
        $this->assertCount(2, $response->viewData('matriz')['filas']);
    }

    public function test_historico_nacional_sin_datos_no_rompe(): void
    {
        $this->get('/historico')
            ->assertStatus(200)
            ->assertSee('Sin datos históricos');
    }

    public function test_dashboard_ultimos_contratos_solo_con_fecha_publicacion(): void
    {
        $fuente = FuenteDatos::create([
            'nombre' => 'Test Fuente 2',
            'slug' => 'test-fuente-dash2',
            'tipo' => 'atom',
            'activo' => true,
        ]);

        $organismo = Organismo::create([
            'nif' => 'P0000011B',
            'nombre' => 'Organismo 2',
        ]);

        // Contrato con fecha
        Contrato::create([
            'placsp_id' => 'DASH-FECHA-001',
            'url_placsp' => 'https://example.com/f1',
            'organismo_id' => $organismo->id,
            'fuente_datos_id' => $fuente->id,
            'version' => 1,
            'fecha_publicacion' => '2024-03-01',
        ]);

        // Contrato sin fecha (no debe aparecer en últimos contratos)
        Contrato::create([
            'placsp_id' => 'DASH-NOFECHA-001',
            'url_placsp' => 'https://example.com/f2',
            'organismo_id' => $organismo->id,
            'fuente_datos_id' => $fuente->id,
            'version' => 1,
        ]);

        $response = $this->get('/');
        $response->assertStatus(200);

        $ultimosContratos = $response->viewData('ultimosContratos');
        $ids = $ultimosContratos->pluck('placsp_id')->toArray();

        $this->assertContains('DASH-FECHA-001', $ids);
        $this->assertNotContains('DASH-NOFECHA-001', $ids);
    }
}
