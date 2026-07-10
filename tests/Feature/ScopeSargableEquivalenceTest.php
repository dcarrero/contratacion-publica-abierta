<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Contrato;
use App\Models\FuenteDatos;
use App\Models\Organismo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Guarda la equivalencia de los scopes sargables (plan 034) con la lógica antigua:
 * scopeYear == whereYear, scopeCcaa == LIKE 'prefijo%'. Si alguien revierte los scopes
 * a la versión no sargable (o rompe el cálculo de rango NUTS), este test lo detecta.
 */
class ScopeSargableEquivalenceTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private int $orgId;

    private int $fuenteId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->orgId = Organismo::create(['nif' => 'P9999999X', 'nombre' => 'Org Test'])->id;
        $this->fuenteId = FuenteDatos::firstOrCreate(
            ['slug' => 'test-034'],
            ['nombre' => 'Test', 'tipo' => 'atom', 'activo' => true]
        )->id;
    }

    private function make(?string $nuts, ?string $fecha): void
    {
        $this->seq++;
        Contrato::create([
            'placsp_id' => 'T034-'.$this->seq,
            'url_placsp' => 'https://example.com/'.$this->seq,
            'organismo_id' => $this->orgId,
            'nuts' => $nuts,
            'importe_adjudicacion' => 1000,
            'fecha_publicacion' => $fecha,
            'fuente_datos_id' => $this->fuenteId,
            'version' => 1,
        ]);
    }

    public function test_scope_year_equivale_a_whereyear(): void
    {
        foreach (['2023-12-31', '2024-01-01', '2024-06-15', '2024-12-31', '2025-01-01', null] as $f) {
            $this->make('ES420', $f);
        }

        foreach ([2023, 2024, 2025] as $year) {
            $nuevo = Contrato::query()->year($year)->orderBy('id')->pluck('id')->all();
            $viejo = Contrato::query()->whereYear('fecha_publicacion', $year)->orderBy('id')->pluck('id')->all();
            $this->assertSame($viejo, $nuevo, "scopeYear($year) difiere de whereYear");
        }
    }

    public function test_scope_ccaa_equivale_a_like_prefijo(): void
    {
        // Incluye casos límite: prefijo exacto, más largo, terminado en '9' (acarreo a ':'),
        // letra final, límite superior 'ES43', y NUTS no relacionados.
        foreach (['ES42', 'ES421', 'ES429', 'ES4290', 'ES42Z', 'ES43', 'ES5', 'ESZZ', null] as $n) {
            $this->make($n, '2024-01-01');
        }

        foreach (['ES42', 'ES421', 'ES429', 'ES4', 'ES43'] as $prefijo) {
            $nuevo = Contrato::query()->ccaa($prefijo)->orderBy('id')->pluck('id')->all();
            $viejo = Contrato::query()->where('nuts', 'LIKE', $prefijo.'%')->orderBy('id')->pluck('id')->all();
            $this->assertSame($viejo, $nuevo, "scopeCcaa($prefijo) difiere de LIKE '{$prefijo}%'");
        }
    }
}
