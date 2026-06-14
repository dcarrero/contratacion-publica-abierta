<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Adjudicatario;
use App\Models\ComunidadAutonoma;
use App\Models\Contrato;
use App\Models\FuenteDatos;
use App\Models\Organismo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContratoApiTest extends TestCase
{
    use RefreshDatabase;

    private FuenteDatos $fuente;

    private Organismo $organismo;

    private Adjudicatario $adjudicatario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fuente = FuenteDatos::create([
            'nombre' => 'PLACSP Test',
            'slug' => 'placsp-test',
            'tipo' => 'atom',
            'activo' => true,
        ]);

        $this->organismo = Organismo::create([
            'nif' => 'P4200001H',
            'nombre' => 'Ayuntamiento Test',
        ]);

        $this->adjudicatario = Adjudicatario::create([
            'nif' => 'B02123456',
            'nombre' => 'Empresa Test SL',
        ]);
    }

    private function makeContrato(array $overrides = []): Contrato
    {
        static $seq = 0;
        $seq++;

        return Contrato::create(array_merge([
            'placsp_id' => "TEST-{$seq}-".uniqid(),
            'url_placsp' => 'https://contrataciondelestado.es/test',
            'organismo_id' => $this->organismo->id,
            'adjudicatario_id' => $this->adjudicatario->id,
            'importe_adjudicacion' => 10000.00,
            'es_menor' => false,
            'fuente_datos_id' => $this->fuente->id,
            'version' => 1,
            'fecha_publicacion' => '2024-03-15',
            'nuts' => 'ES30',
        ], $overrides));
    }

    // -----------------------------------------------------------------------
    // Estructura básica de respuesta
    // -----------------------------------------------------------------------

    public function test_returns_200_json_with_data_and_pagination_meta(): void
    {
        $this->makeContrato();

        $response = $this->getJson('/api/v1/contratos');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'placsp_id',
                        'objeto',
                        'tipo_contrato',
                        'procedimiento',
                        'estado',
                        'importe_adjudicacion',
                        'importe_adjudicacion_con_iva',
                        'fecha_publicacion',
                        'fecha_adjudicacion',
                        'fecha_formalizacion',
                        'nuts',
                        'cpv',
                        'es_menor',
                        'num_ofertas',
                        'url_placsp',
                        'organismo',
                        'adjudicatario',
                    ],
                ],
                'links' => ['first', 'last', 'prev', 'next'],
                'meta' => ['current_page', 'per_page', 'total', 'last_page'],
            ]);
    }

    public function test_returns_correct_resource_field_values(): void
    {
        $contrato = $this->makeContrato([
            'objeto' => 'Servicio de limpieza',
            'tipo_contrato' => 'Servicios',
            'importe_adjudicacion' => 45000.00,
            'fecha_publicacion' => '2024-03-15',
            'nuts' => 'ES30',
        ]);

        $response = $this->getJson('/api/v1/contratos');

        $response->assertStatus(200);

        $data = $response->json('data.0');

        $this->assertSame($contrato->placsp_id, $data['placsp_id']);
        $this->assertSame('Servicio de limpieza', $data['objeto']);
        $this->assertSame('Servicios', $data['tipo_contrato']);
        $this->assertSame('2024-03-15', $data['fecha_publicacion']);

        // Organismo y adjudicatario anidados
        $this->assertSame('P4200001H', $data['organismo']['nif']);
        $this->assertSame('Ayuntamiento Test', $data['organismo']['nombre']);
        $this->assertSame('B02123456', $data['adjudicatario']['nif']);
        $this->assertSame('Empresa Test SL', $data['adjudicatario']['nombre']);
    }

    // -----------------------------------------------------------------------
    // Paginación
    // -----------------------------------------------------------------------

    public function test_pagination_defaults_to_25_per_page(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->makeContrato();
        }

        $response = $this->getJson('/api/v1/contratos');

        $response->assertStatus(200);
        $this->assertCount(25, $response->json('data'));
        $this->assertSame(25, $response->json('meta.per_page'));
        $this->assertSame(30, $response->json('meta.total'));
        $this->assertSame(2, $response->json('meta.last_page'));
    }

    public function test_per_page_parameter_respects_custom_value(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->makeContrato();
        }

        $response = $this->getJson('/api/v1/contratos?per_page=5');

        $response->assertStatus(200);
        $this->assertCount(5, $response->json('data'));
        $this->assertSame(5, $response->json('meta.per_page'));
    }

    public function test_per_page_above_100_returns_422(): void
    {
        $response = $this->getJson('/api/v1/contratos?per_page=101');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['per_page']);
    }

    // -----------------------------------------------------------------------
    // Filtro ccaa
    // -----------------------------------------------------------------------

    public function test_ccaa_filter_returns_only_matching_contracts(): void
    {
        // Crear la CCAA ES42 en BD (necesario para validación)
        ComunidadAutonoma::create([
            'codigo_ine' => '08',
            'nombre' => 'Castilla-La Mancha',
            'nuts' => 'ES42',
        ]);

        // 2 contratos CLM (ES42) + 1 nacional (ES30)
        $this->makeContrato(['nuts' => 'ES42']);
        $this->makeContrato(['nuts' => 'ES42']);
        $this->makeContrato(['nuts' => 'ES30']);

        $response = $this->getJson('/api/v1/contratos?ccaa=ES42');

        $response->assertStatus(200);
        $this->assertSame(2, $response->json('meta.total'));

        foreach ($response->json('data') as $item) {
            $this->assertStringStartsWith('ES42', $item['nuts']);
        }
    }

    public function test_invalid_ccaa_returns_422(): void
    {
        $response = $this->getJson('/api/v1/contratos?ccaa=XX99');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['ccaa']);
    }

    // -----------------------------------------------------------------------
    // Filtro year
    // -----------------------------------------------------------------------

    public function test_year_filter_returns_only_matching_year(): void
    {
        $this->makeContrato(['fecha_publicacion' => '2024-06-01']);
        $this->makeContrato(['fecha_publicacion' => '2024-09-15']);
        $this->makeContrato(['fecha_publicacion' => '2023-03-10']);

        $response = $this->getJson('/api/v1/contratos?year=2024');

        $response->assertStatus(200);
        $this->assertSame(2, $response->json('meta.total'));
    }

    // -----------------------------------------------------------------------
    // Sin datos
    // -----------------------------------------------------------------------

    public function test_empty_table_returns_empty_data_array(): void
    {
        $response = $this->getJson('/api/v1/contratos');

        $response->assertStatus(200);
        $this->assertCount(0, $response->json('data'));
        $this->assertSame(0, $response->json('meta.total'));
    }
}
