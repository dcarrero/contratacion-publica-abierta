<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\FuenteDatos;
use App\Models\Provincia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migrations_run_without_errors(): void
    {
        // Si llegamos aquí, RefreshDatabase ya ejecutó las migraciones sin error
        $this->assertTrue(true);
    }

    public function test_seeders_create_provincias(): void
    {
        $this->seed();

        $this->assertDatabaseCount('comunidades_autonomas', 19);
        $this->assertDatabaseCount('provincias', 52);
        $this->assertDatabaseHas('provincias', ['codigo_ine' => '02', 'nombre' => 'Albacete']);
        $this->assertDatabaseHas('provincias', ['codigo_ine' => '45', 'nombre' => 'Toledo']);
        $this->assertDatabaseHas('provincias', ['codigo_ine' => '28', 'nombre' => 'Madrid']);
        $this->assertDatabaseHas('comunidades_autonomas', ['nuts' => 'ES42', 'nombre' => 'Castilla-La Mancha']);
    }

    public function test_seeders_create_fuentes_datos(): void
    {
        $this->seed();

        // El conteo exacto crece al añadir fuentes; verificamos un mínimo + slugs clave.
        $this->assertGreaterThanOrEqual(18, FuenteDatos::count());
        $this->assertDatabaseHas('fuentes_datos', ['slug' => 'placsp-licitaciones']);
        $this->assertDatabaseHas('fuentes_datos', ['slug' => 'bquant-bootstrap']);
        $this->assertDatabaseHas('fuentes_datos', ['slug' => 'cat-contractacio']);
        $this->assertDatabaseHas('fuentes_datos', ['slug' => 'anda-menores']);
        $this->assertDatabaseHas('fuentes_datos', ['slug' => 'eusk-contratos']);
        $this->assertDatabaseHas('fuentes_datos', ['slug' => 'cyl-contratos']);
        $this->assertDatabaseHas('fuentes_datos', ['slug' => 'ast-contratos']);
    }

    public function test_seeders_are_idempotent(): void
    {
        $this->seed();
        $fuentesTrasPrimerSeed = FuenteDatos::count();

        $this->seed();

        $this->assertDatabaseCount('comunidades_autonomas', 19);
        $this->assertDatabaseCount('provincias', 52);
        $this->assertSame($fuentesTrasPrimerSeed, FuenteDatos::count());
    }

    public function test_provincia_model_attributes(): void
    {
        $this->seed();

        $provincia = Provincia::where('codigo_ine', '13')->first();
        $this->assertNotNull($provincia);
        $this->assertSame('Ciudad Real', $provincia->nombre);
    }

    public function test_fuente_datos_model_casts(): void
    {
        $this->seed();

        $fuente = FuenteDatos::where('slug', 'placsp-licitaciones')->first();
        $this->assertNotNull($fuente);
        $this->assertTrue($fuente->activo);
        $this->assertSame('atom', $fuente->tipo);
    }
}
