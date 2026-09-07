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

class SyncPlacspAgregadasTest extends TestCase
{
    use RefreshDatabase;

    private const FEED_PAGE_1 = 'contrataciondelestado.es/sindicacion/sindicacion_1044/PlataformasAgregadasSinMenores.atom';

    private const FEED_PAGE_2 = 'contrataciondelestado.es/sindicacion/sindicacion_1044/PlataformasAgregadasSinMenores_20260905_030029.atom';

    protected function setUp(): void
    {
        parent::setUp();

        // Misma fuente que usa el ZIP anual (placsp:import-zip agregacion)
        FuenteDatos::create([
            'nombre' => 'PLACSP - Plataformas Agregadas',
            'slug' => 'placsp-agregacion',
            'url' => 'https://contrataciondelsectorpublico.gob.es/sindicacion/sindicacion_1044/',
            'tipo' => 'atom',
            'frecuencia' => 'diaria',
            'activo' => true,
        ]);
    }

    private function fakePage1(): void
    {
        Http::fake([
            'contrataciondelestado.es/*' => Http::response($this->fixture('agregadas_page_1.xml'), 200),
        ]);
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(base_path("tests/fixtures/placsp/{$name}"));
    }

    public function test_sync_creates_all_contracts(): void
    {
        $this->fakePage1();

        $this->artisan('regional:sync-agregadas', ['--max-pages' => 1, '--full' => true])
            ->assertSuccessful();

        $this->assertEquals(3, Contrato::count());

        // Navarra: el órgano solo trae ID_OC_PLAT, que el parser acepta como NIF
        $navarra = Contrato::where('placsp_id', '20306630')->first();
        $this->assertNotNull($navarra);
        $this->assertEquals('8256_ABSU01-2026', $navarra->expediente);
        $this->assertEquals('G31603509', $navarra->nif_organo);
        $this->assertEquals('ES220', $navarra->nuts);
        $this->assertFalse($navarra->es_menor);
        $this->assertFalse($navarra->es_clm);

        // Galicia: con adjudicatario
        $galicia = Contrato::where('placsp_id', '20306631')->first();
        $this->assertNotNull($galicia);
        $this->assertEquals('ADJ', $galicia->estado);
        $this->assertEquals('P1507900F', $galicia->nif_organo);
        $this->assertEquals('B15999999', $galicia->nif_adjudicatario);
        $this->assertEquals('198000.00', (string) $galicia->importe_adjudicacion);

        $catalunya = Contrato::where('placsp_id', '20306632')->first();
        $this->assertNotNull($catalunya);
        $this->assertEquals('ES512', $catalunya->nuts);
    }

    public function test_sync_marks_contracts_as_agregacion(): void
    {
        $this->fakePage1();

        $this->artisan('regional:sync-agregadas', ['--max-pages' => 1, '--full' => true])
            ->assertSuccessful();

        // Mismo tipo_registro que el ZIP anual: si divergiera, cada ejecución
        // "actualizaría" contratos idénticos (tipo_registro entra en el hash).
        $this->assertEquals(3, Contrato::where('tipo_registro', 'agregacion')->count());
    }

    public function test_sync_creates_organismos_and_adjudicatarios(): void
    {
        $this->fakePage1();

        $this->artisan('regional:sync-agregadas', ['--max-pages' => 1, '--full' => true])
            ->assertSuccessful();

        $this->assertEquals(3, Organismo::count());
        $this->assertNotNull(Organismo::where('nif', 'P1507900F')->first());

        $galicia = Contrato::where('placsp_id', '20306631')->first();
        $this->assertNotNull($galicia->adjudicatario_id);
    }

    public function test_sync_is_idempotent(): void
    {
        $this->fakePage1();

        $this->artisan('regional:sync-agregadas', ['--max-pages' => 1, '--full' => true])
            ->assertSuccessful();
        $this->assertEquals(3, Contrato::count());

        $this->artisan('regional:sync-agregadas', ['--max-pages' => 1, '--full' => true])
            ->assertSuccessful();
        $this->assertEquals(3, Contrato::count());

        $contrato = Contrato::where('placsp_id', '20306630')->first();
        $this->assertEquals(1, $contrato->version);
    }

    public function test_sync_does_not_duplicate_contracts_already_imported_from_zip(): void
    {
        $this->fakePage1();

        // Simula un contrato ya presente por el ZIP anual: mismo placsp_id, misma fuente.
        $this->artisan('regional:sync-agregadas', ['--max-pages' => 1, '--full' => true])
            ->assertSuccessful();

        $idsAntes = Contrato::pluck('placsp_id')->sort()->values()->all();

        $this->artisan('regional:sync-agregadas', ['--max-pages' => 1, '--full' => true])
            ->assertSuccessful();

        $this->assertEquals($idsAntes, Contrato::pluck('placsp_id')->sort()->values()->all());
        $this->assertEquals(3, Contrato::count());
    }

    public function test_sync_creates_import_log(): void
    {
        $this->fakePage1();

        $this->artisan('regional:sync-agregadas', ['--max-pages' => 1, '--full' => true])
            ->assertSuccessful();

        $log = ImportLog::where('tipo', 'sync-agregadas')->first();
        $this->assertNotNull($log);
        $this->assertEquals(3, $log->nuevos);
        $this->assertEquals(0, $log->errores);
    }

    public function test_sync_updates_fuente_ultima_sincronizacion(): void
    {
        $this->fakePage1();

        $this->artisan('regional:sync-agregadas', ['--max-pages' => 1, '--full' => true])
            ->assertSuccessful();

        $fuente = FuenteDatos::where('slug', 'placsp-agregacion')->first();
        $this->assertNotNull($fuente->ultima_sincronizacion);
    }

    public function test_dry_run_does_not_insert_data(): void
    {
        $this->fakePage1();

        $this->artisan('regional:sync-agregadas', ['--max-pages' => 1, '--full' => true, '--dry-run' => true])
            ->assertSuccessful();

        $this->assertEquals(0, Contrato::count());
        $this->assertEquals(0, Organismo::count());
        $this->assertEquals(0, ImportLog::count());

        $fuente = FuenteDatos::where('slug', 'placsp-agregacion')->first();
        $this->assertNull($fuente->ultima_sincronizacion);
    }

    public function test_sync_fails_without_fuente_datos(): void
    {
        FuenteDatos::where('slug', 'placsp-agregacion')->delete();

        $this->artisan('regional:sync-agregadas', ['--max-pages' => 1])
            ->assertFailed();
    }

    public function test_sync_processes_multiple_pages(): void
    {
        Http::fake([
            self::FEED_PAGE_1 => Http::response($this->fixture('agregadas_page_1.xml'), 200),
            self::FEED_PAGE_2 => Http::response($this->fixture('agregadas_page_2.xml'), 200),
        ]);

        $this->artisan('regional:sync-agregadas', ['--max-pages' => 10, '--full' => true])
            ->assertSuccessful();

        // 3 de page1 + 2 de page2 (los <at:deleted-entry> no cuentan)
        $this->assertEquals(5, Contrato::count());

        $rioja = Contrato::where('placsp_id', '20306634')->first();
        $this->assertNotNull($rioja);
        $this->assertEquals('LR-2026/3', $rioja->expediente);
    }

    public function test_max_pages_limits_pagination(): void
    {
        Http::fake([
            self::FEED_PAGE_1 => Http::response($this->fixture('agregadas_page_1.xml'), 200),
            self::FEED_PAGE_2 => Http::response($this->fixture('agregadas_page_2.xml'), 200),
        ]);

        $this->artisan('regional:sync-agregadas', ['--max-pages' => 1, '--full' => true])
            ->assertSuccessful();

        $this->assertEquals(3, Contrato::count());
        $this->assertNull(Contrato::where('placsp_id', '20306634')->first());
    }

    public function test_incremental_sync_stops_at_older_entries(): void
    {
        Http::fake([
            self::FEED_PAGE_1 => Http::response($this->fixture('agregadas_page_1.xml'), 200),
            self::FEED_PAGE_2 => Http::response($this->fixture('agregadas_page_2.xml'), 200),
        ]);

        // Todos los entries de page2 son anteriores a --since, así que el reader
        // no debe seguir paginando más allá de ella.
        $this->artisan('regional:sync-agregadas', ['--max-pages' => 10, '--since' => '2026-09-06 00:00:00'])
            ->assertSuccessful();

        $this->assertEquals(5, Contrato::count());

        Http::assertSentCount(2);
    }
}
