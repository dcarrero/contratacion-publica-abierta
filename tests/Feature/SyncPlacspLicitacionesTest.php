<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Contrato;
use App\Models\FuenteDatos;
use App\Models\ImportLog;
use App\Models\Organismo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncPlacspLicitacionesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Crear fuentes de datos necesarias
        FuenteDatos::create([
            'nombre' => 'PLACSP - Licitaciones',
            'slug' => 'placsp-licitaciones',
            'url' => 'https://contrataciondelestado.es/sindicacion/sindicacion_643/licitacionesPerfilesContratanteCompleto3.atom',
            'tipo' => 'atom',
            'frecuencia' => 'diaria',
            'activo' => true,
        ]);
    }

    public function test_sync_creates_all_contracts(): void
    {
        $page1 = file_get_contents(base_path('tests/fixtures/placsp/atom_page_1.xml'));

        Http::fake([
            'contrataciondelestado.es/*' => Http::response($page1, 200),
        ]);

        $this->artisan('placsp:sync-licitaciones', ['--max-pages' => 1, '--full' => true])
            ->assertSuccessful();

        // Todos los entries se importan (sin filtro CLM)
        $this->assertEquals(3, Contrato::count());

        // Verificar datos del primer contrato (Toledo, CLM)
        $contrato1 = Contrato::where('placsp_id', '12345001')->first();
        $this->assertNotNull($contrato1);
        $this->assertEquals('EXP-2026/001', $contrato1->expediente);
        $this->assertEquals('PUB', $contrato1->estado);
        $this->assertEquals('P4500000A', $contrato1->nif_organo);
        $this->assertTrue($contrato1->es_clm);  // NUTS ES425 → es_clm derivado
        $this->assertFalse($contrato1->es_menor);
        $this->assertEquals('licitacion', $contrato1->tipo_registro);

        // Verificar contrato con adjudicación (Ciudad Real, CLM)
        $contrato2 = Contrato::where('placsp_id', '12345002')->first();
        $this->assertNotNull($contrato2);
        $this->assertEquals('ADJ', $contrato2->estado);
        $this->assertEquals('B13999999', $contrato2->nif_adjudicatario);
        $this->assertTrue($contrato2->es_clm);  // NUTS ES422 → es_clm derivado

        // Verificar contrato no-CLM (La Rioja)
        $contrato3 = Contrato::where('placsp_id', '12345003')->first();
        $this->assertNotNull($contrato3);
        $this->assertFalse($contrato3->es_clm);  // NUTS ES230 → es_clm = false
    }

    public function test_sync_creates_organismos_automatically(): void
    {
        $page1 = file_get_contents(base_path('tests/fixtures/placsp/atom_page_1.xml'));

        Http::fake([
            'contrataciondelestado.es/*' => Http::response($page1, 200),
        ]);

        $this->artisan('placsp:sync-licitaciones', ['--max-pages' => 1, '--full' => true])
            ->assertSuccessful();

        // Tres organismos creados (todos los entries)
        $this->assertEquals(3, Organismo::count());

        $toledo = Organismo::where('nif', 'P4500000A')->first();
        $this->assertNotNull($toledo);
        $this->assertEquals('Diputación Provincial de Toledo', $toledo->nombre);
    }

    public function test_sync_is_idempotent(): void
    {
        $page1 = file_get_contents(base_path('tests/fixtures/placsp/atom_page_1.xml'));

        Http::fake([
            'contrataciondelestado.es/*' => Http::response($page1, 200),
        ]);

        // Primera ejecución
        $this->artisan('placsp:sync-licitaciones', ['--max-pages' => 1, '--full' => true])
            ->assertSuccessful();
        $this->assertEquals(3, Contrato::count());

        // Segunda ejecución — mismos datos, sin duplicados
        $this->artisan('placsp:sync-licitaciones', ['--max-pages' => 1, '--full' => true])
            ->assertSuccessful();
        $this->assertEquals(3, Contrato::count());

        // Versión no debería incrementar (hash idéntico)
        $contrato = Contrato::where('placsp_id', '12345001')->first();
        $this->assertEquals(1, $contrato->version);
    }

    public function test_sync_creates_import_log(): void
    {
        $page1 = file_get_contents(base_path('tests/fixtures/placsp/atom_page_1.xml'));

        Http::fake([
            'contrataciondelestado.es/*' => Http::response($page1, 200),
        ]);

        $this->artisan('placsp:sync-licitaciones', ['--max-pages' => 1, '--full' => true])
            ->assertSuccessful();

        $log = ImportLog::where('tipo', 'sync-licitaciones')->first();
        $this->assertNotNull($log);
        $this->assertEquals(3, $log->nuevos);
        $this->assertEquals(0, $log->errores);
    }

    public function test_sync_updates_fuente_ultima_sincronizacion(): void
    {
        $page1 = file_get_contents(base_path('tests/fixtures/placsp/atom_page_1.xml'));

        Http::fake([
            'contrataciondelestado.es/*' => Http::response($page1, 200),
        ]);

        $this->artisan('placsp:sync-licitaciones', ['--max-pages' => 1, '--full' => true])
            ->assertSuccessful();

        $fuente = FuenteDatos::where('slug', 'placsp-licitaciones')->first();
        $this->assertNotNull($fuente->ultima_sincronizacion);
    }

    public function test_dry_run_does_not_insert_data(): void
    {
        $page1 = file_get_contents(base_path('tests/fixtures/placsp/atom_page_1.xml'));

        Http::fake([
            'contrataciondelestado.es/*' => Http::response($page1, 200),
        ]);

        $this->artisan('placsp:sync-licitaciones', ['--max-pages' => 1, '--full' => true, '--dry-run' => true])
            ->assertSuccessful();

        $this->assertEquals(0, Contrato::count());
        $this->assertEquals(0, ImportLog::count());
    }

    public function test_sync_fails_without_fuente_datos(): void
    {
        FuenteDatos::where('slug', 'placsp-licitaciones')->delete();

        $this->artisan('placsp:sync-licitaciones', ['--max-pages' => 1])
            ->assertFailed();
    }

    public function test_sync_processes_multiple_pages(): void
    {
        $page1 = file_get_contents(base_path('tests/fixtures/placsp/atom_page_1.xml'));
        $page2 = file_get_contents(base_path('tests/fixtures/placsp/atom_page_2.xml'));

        Http::fake([
            'contrataciondelestado.es/sindicacion/sindicacion_643/licitacionesPerfilesContratanteCompleto3.atom' => Http::response($page1, 200),
            'contrataciondelestado.es/sindicacion/sindicacion_643/licitacionesPerfilesContratanteCompleto3_20260301_120000.atom' => Http::response($page2, 200),
        ]);

        $this->artisan('placsp:sync-licitaciones', ['--max-pages' => 10, '--full' => true])
            ->assertSuccessful();

        // 3 de page1 + 2 de page2 = 5 contratos
        $this->assertEquals(5, Contrato::count());

        // Verificar contrato de Albacete (page 2)
        $albacete = Contrato::where('placsp_id', '12345004')->first();
        $this->assertNotNull($albacete);
        $this->assertEquals('AB-2026/010', $albacete->expediente);
    }
}
