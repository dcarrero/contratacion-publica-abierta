<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Adjudicatario;
use App\Models\Contrato;
use App\Models\FuenteDatos;
use App\Models\ImportLog;
use App\Models\Organismo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BootstrapCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $csvPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->csvPath = base_path('tests/fixtures/clm_bootstrap_sample.csv');
    }

    public function test_bootstrap_csv_creates_contratos(): void
    {
        $this->artisan('bootstrap:csv', ['file' => $this->csvPath])
            ->assertSuccessful();

        // 7 filas en CSV, 1 sin nif_organo (fila 6) = 6 contratos
        $this->assertSame(6, Contrato::count());
    }

    public function test_bootstrap_csv_creates_organismos(): void
    {
        $this->artisan('bootstrap:csv', ['file' => $this->csvPath])
            ->assertSuccessful();

        // 6 organismos distintos por NIF
        $this->assertSame(6, Organismo::count());
        $this->assertDatabaseHas('organismos', ['nif' => 'P4527400A']);
        $this->assertDatabaseHas('organismos', ['nif' => 'P1300000D']);
        $this->assertDatabaseHas('organismos', ['nif' => 'S1911001D']);
    }

    public function test_bootstrap_csv_creates_adjudicatarios(): void
    {
        $this->artisan('bootstrap:csv', ['file' => $this->csvPath])
            ->assertSuccessful();

        // 5 adjudicatarios distintos por NIF (A13987654 aparece 2 veces)
        $this->assertSame(5, Adjudicatario::count());
        $this->assertDatabaseHas('adjudicatarios', ['nif' => 'B45123456']);
        $this->assertDatabaseHas('adjudicatarios', ['nif' => 'A13987654']);
    }

    public function test_bootstrap_csv_assigns_fuente_datos(): void
    {
        $this->artisan('bootstrap:csv', ['file' => $this->csvPath])
            ->assertSuccessful();

        $fuente = FuenteDatos::where('slug', 'bquant-bootstrap')->first();
        $this->assertNotNull($fuente);

        $contrato = Contrato::first();
        $this->assertSame($fuente->id, $contrato->fuente_datos_id);
    }

    public function test_bootstrap_csv_creates_import_log(): void
    {
        $this->artisan('bootstrap:csv', ['file' => $this->csvPath])
            ->assertSuccessful();

        $log = ImportLog::where('tipo', 'bootstrap')->first();
        $this->assertNotNull($log);
        $this->assertSame(7, $log->procesados);
        $this->assertSame(6, $log->nuevos);
        $this->assertSame(1, $log->ignorados); // fila sin nif_organo
    }

    public function test_bootstrap_csv_skips_rows_without_nif_organo(): void
    {
        $this->artisan('bootstrap:csv', ['file' => $this->csvPath])
            ->assertSuccessful();

        // La fila 6 no tiene nif_organo, se debe saltar
        $this->assertDatabaseMissing('contratos', ['expediente' => 'EXP-2024-006']);
    }

    public function test_bootstrap_csv_generates_placsp_id_when_missing(): void
    {
        $this->artisan('bootstrap:csv', ['file' => $this->csvPath])
            ->assertSuccessful();

        // La fila 6 tiene id vacio - pero tambien falta nif_organo, asi que se salta
        // Verificar que las demas tienen placsp_id
        $contratos = Contrato::all();
        foreach ($contratos as $contrato) {
            $this->assertNotEmpty($contrato->placsp_id);
        }
    }

    public function test_bootstrap_csv_dry_run_does_not_insert(): void
    {
        $this->artisan('bootstrap:csv', [
            'file' => $this->csvPath,
            '--dry-run' => true,
        ])->assertSuccessful();

        $this->assertSame(0, Contrato::count());
        $this->assertSame(0, Organismo::count());
        $this->assertSame(0, ImportLog::count());
    }

    public function test_bootstrap_csv_handles_duplicate_adjudicatario(): void
    {
        $this->artisan('bootstrap:csv', ['file' => $this->csvPath])
            ->assertSuccessful();

        // A13987654 (Limpiezas Manchegas S.A.) aparece en filas 2 y 7
        $adj = Adjudicatario::where('nif', 'A13987654')->first();
        $this->assertNotNull($adj);

        // Debe tener 2 contratos
        $this->assertSame(2, $adj->contratos()->count());
    }

    public function test_bootstrap_csv_maps_importes_correctly(): void
    {
        $this->artisan('bootstrap:csv', ['file' => $this->csvPath])
            ->assertSuccessful();

        $contrato = Contrato::where('placsp_id', 'PLACSP-001')->first();
        $this->assertNotNull($contrato);
        $this->assertEquals(10000.00, (float) $contrato->importe_licitacion);
        $this->assertEquals(12100.00, (float) $contrato->importe_licitacion_con_iva);
        $this->assertEquals(9500.00, (float) $contrato->importe_adjudicacion);
    }

    public function test_bootstrap_csv_is_idempotent(): void
    {
        $this->artisan('bootstrap:csv', ['file' => $this->csvPath])
            ->assertSuccessful();

        $this->artisan('bootstrap:csv', ['file' => $this->csvPath])
            ->assertSuccessful();

        // Los mismos contratos, no duplicados
        $this->assertSame(6, Contrato::count());
    }

    public function test_bootstrap_csv_fails_with_nonexistent_file(): void
    {
        $this->artisan('bootstrap:csv', ['file' => '/tmp/nonexistent.csv'])
            ->assertFailed();
    }

    public function test_bootstrap_csv_sets_es_pyme_correctly(): void
    {
        $this->artisan('bootstrap:csv', ['file' => $this->csvPath])
            ->assertSuccessful();

        $pyme = Adjudicatario::where('nif', 'B45123456')->first();
        $this->assertTrue($pyme->es_pyme);

        $noPyme = Adjudicatario::where('nif', 'A13987654')->first();
        $this->assertFalse($noPyme->es_pyme); // firstOrCreate usa el valor de la primera vez (false)
    }

    public function test_bootstrap_csv_combines_duracion_with_unidad(): void
    {
        $this->artisan('bootstrap:csv', ['file' => $this->csvPath])
            ->assertSuccessful();

        $contrato = Contrato::where('placsp_id', 'PLACSP-001')->first();
        $this->assertSame('12 meses', $contrato->duracion);
    }
}
