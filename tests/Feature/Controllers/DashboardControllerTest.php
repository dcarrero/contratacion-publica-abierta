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
        $response->assertViewHas('kpis');
        $response->assertViewHas('topEmpresas');
        $response->assertViewHas('topOrganismos');
        $response->assertViewHas('ultimosContratos');

        $kpis = $response->viewData('kpis');
        // total_contratos se suma desde Organismo::sum('total_contratos')
        $this->assertSame(5, (int) $kpis['total_contratos']);
        $this->assertSame(1, (int) $kpis['total_organismos']);
        $this->assertSame(1, (int) $kpis['total_adjudicatarios']);
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
