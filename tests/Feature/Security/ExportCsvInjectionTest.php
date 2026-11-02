<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Adjudicatario;
use App\Models\Organismo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Auditoría 2026-11 (XSS-03): los nombres que llegan de fuentes externas no pueden
 * salir en el CSV como fórmula de hoja de cálculo.
 */
class ExportCsvInjectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_los_nombres_con_formula_salen_escapados(): void
    {
        Organismo::forceCreate(['nif' => 'P0000001A', 'nombre' => '=HYPERLINK("http://x","clic")']);
        Adjudicatario::forceCreate(['nif' => 'B00000001', 'nombre' => '  @SUM(1+1)']);

        $organismos = $this->get('/export/organismos.csv')->streamedContent();
        $adjudicatarios = $this->get('/export/adjudicatarios.csv')->streamedContent();

        $this->assertStringContainsString("'=HYPERLINK", $organismos);
        $this->assertStringContainsString("'  @SUM(1+1)", $adjudicatarios);
    }
}
