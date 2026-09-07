<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\Adjudicatario;
use App\Models\Contrato;
use App\Models\FuenteDatos;
use App\Models\Organismo;
use App\Services\RedRelacionesBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RedRelacionesBuilderTest extends TestCase
{
    use RefreshDatabase;

    private RedRelacionesBuilder $builder;

    private ?FuenteDatos $fuente = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->builder = new RedRelacionesBuilder;
    }

    private function contrato(Organismo $organismo, ?Adjudicatario $adjudicatario, string $fecha, float $importe): void
    {
        static $seq = 0;
        $seq++;

        $this->fuente ??= FuenteDatos::where('slug', 'bquant-bootstrap')->firstOrFail();

        Contrato::create([
            'placsp_id' => 'RED-'.$seq,
            'organismo_id' => $organismo->id,
            'adjudicatario_id' => $adjudicatario?->id,
            'fecha_publicacion' => $fecha,
            'importe_adjudicacion' => $importe,
            'fuente_datos_id' => $this->fuente->id,
            'hash_contenido' => 'hash-red-'.$seq,
        ]);
    }

    private function organismo(string $nif): Organismo
    {
        return Organismo::create(['nif' => $nif, 'nombre' => 'Organismo '.$nif, 'nombre_normalizado' => 'ORG '.$nif]);
    }

    private function empresa(string $nif): Adjudicatario
    {
        return Adjudicatario::create(['nif' => $nif, 'nombre' => 'Empresa '.$nif, 'nombre_normalizado' => 'EMP '.$nif]);
    }

    public function test_construye_los_dos_niveles_alrededor_de_la_empresa(): void
    {
        $org = $this->organismo('P0000001A');
        $yo = $this->empresa('B00000001');
        $competidora = $this->empresa('B00000002');

        $this->contrato($org, $yo, '2026-03-01', 1000.00);
        $this->contrato($org, $competidora, '2026-04-01', 4000.00);

        $red = $this->builder->paraAdjudicatario($yo->id, 2026);

        $niveles = collect($red['nodes'])->groupBy('nivel')->map->count();
        $this->assertSame(1, $niveles[0]);  // la propia empresa
        $this->assertSame(1, $niveles[1]);  // el organismo que le adjudica
        $this->assertSame(1, $niveles[2]);  // la otra empresa en ese organismo

        $this->assertCount(2, $red['links']);
        $this->assertSame(1, $red['meta']['directas']);
        $this->assertSame(1, $red['meta']['compartidas']);
        $this->assertSame('adjudicatario', $red['meta']['tipo_centro']);
    }

    public function test_la_entidad_central_no_aparece_como_su_propio_competidor(): void
    {
        $org = $this->organismo('P0000001A');
        $yo = $this->empresa('B00000001');

        $this->contrato($org, $yo, '2026-03-01', 1000.00);
        $this->contrato($org, $yo, '2026-05-01', 2000.00);

        $red = $this->builder->paraAdjudicatario($yo->id, 2026);

        $this->assertSame(0, $red['meta']['compartidas']);
        $this->assertCount(2, $red['nodes']);
    }

    public function test_acota_la_red_a_la_ventana_de_tres_anios(): void
    {
        $org = $this->organismo('P0000001A');
        $yo = $this->empresa('B00000001');
        $otra = $this->empresa('B00000002');

        // Dentro de la ventana 2024-2026.
        $this->contrato($org, $yo, '2024-01-01', 1000.00);
        // Fuera: no debe arrastrar ni el organismo ni la competidora.
        $orgViejo = $this->organismo('P0000009Z');
        $this->contrato($orgViejo, $yo, '2019-01-01', 90000.00);
        $this->contrato($orgViejo, $otra, '2019-02-01', 90000.00);

        $red = $this->builder->paraAdjudicatario($yo->id, 2026);

        $etiquetas = collect($red['nodes'])->pluck('nif')->all();
        $this->assertContains('P0000001A', $etiquetas);
        $this->assertNotContains('P0000009Z', $etiquetas);
        $this->assertSame(2024, $red['meta']['anio_desde']);
        $this->assertSame(2026, $red['meta']['anio_hasta']);
    }

    public function test_red_de_organismo_es_simetrica(): void
    {
        $org = $this->organismo('P0000001A');
        $otroOrg = $this->organismo('P0000002B');
        $proveedor = $this->empresa('B00000001');

        $this->contrato($org, $proveedor, '2026-03-01', 1000.00);
        $this->contrato($otroOrg, $proveedor, '2026-03-05', 5000.00);

        $red = $this->builder->paraOrganismo($org->id, 2026);

        $this->assertSame('organismo', $red['meta']['tipo_centro']);
        $this->assertSame(1, $red['meta']['directas']);      // su proveedor
        $this->assertSame(1, $red['meta']['compartidas']);   // el otro organismo que lo comparte

        $centro = collect($red['nodes'])->firstWhere('nivel', 0);
        $this->assertSame('P0000001A', $centro['nif']);
        $this->assertSame('adjudicatario', collect($red['nodes'])->firstWhere('nivel', 1)['tipo']);
    }

    public function test_entidad_sin_relaciones_en_la_ventana_devuelve_red_vacia(): void
    {
        $org = $this->organismo('P0000001A');
        $yo = $this->empresa('B00000001');
        $this->contrato($org, $yo, '2015-03-01', 1000.00);

        $red = $this->builder->paraAdjudicatario($yo->id, 2026);

        $this->assertSame([], $red['nodes']);
        $this->assertSame([], $red['links']);
        $this->assertSame(0, $red['meta']['directas']);
    }

    public function test_ignora_contratos_sin_adjudicatario(): void
    {
        $org = $this->organismo('P0000001A');
        $yo = $this->empresa('B00000001');

        $this->contrato($org, $yo, '2026-03-01', 1000.00);
        $this->contrato($org, null, '2026-03-02', 8000.00);

        $red = $this->builder->paraAdjudicatario($yo->id, 2026);

        $this->assertSame(0, $red['meta']['compartidas']);
    }
}
