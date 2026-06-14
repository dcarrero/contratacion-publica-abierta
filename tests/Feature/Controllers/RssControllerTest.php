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
 * Tests para RssController.
 *
 * Verifica que /rss/contratos devuelve 200 con content-type XML y hasta 50 ítems.
 * También verifica /rss/organismo/{nif} para organismos existentes y no existentes.
 *
 * Limitación SQLite vs PostgreSQL: suite en SQLite :memory:.
 */
class RssControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function createFuente(): FuenteDatos
    {
        return FuenteDatos::create([
            'nombre' => 'Test Fuente RSS',
            'slug' => 'test-fuente-rss',
            'tipo' => 'atom',
            'activo' => true,
        ]);
    }

    private function createOrganismo(string $nif = 'P0000020A', string $nombre = 'Organismo RSS'): Organismo
    {
        return Organismo::create(['nif' => $nif, 'nombre' => $nombre]);
    }

    public function test_rss_contratos_devuelve_200_y_content_type_xml(): void
    {
        $response = $this->get('/rss/contratos');

        $response->assertStatus(200);
        $this->assertStringContainsString('application/rss+xml', $response->headers->get('Content-Type'));
    }

    public function test_rss_contratos_con_datos_es_xml_valido_y_tiene_items(): void
    {
        $fuente = $this->createFuente();
        $organismo = $this->createOrganismo();
        $adjudicatario = Adjudicatario::create([
            'nif' => 'B20111111',
            'nombre' => 'Empresa RSS S.L.',
        ]);

        // Crear 3 contratos con fecha para que aparezcan en el feed
        for ($i = 1; $i <= 3; $i++) {
            Contrato::create([
                'placsp_id' => "RSS-{$i}",
                'url_placsp' => "https://example.com/rss{$i}",
                'organismo_id' => $organismo->id,
                'adjudicatario_id' => $adjudicatario->id,
                'importe_adjudicacion' => 1000.00 * $i,
                'fuente_datos_id' => $fuente->id,
                'version' => 1,
                'fecha_publicacion' => "2024-0{$i}-15",
                'objeto' => "Contrato RSS {$i}",
            ]);
        }

        $response = $this->get('/rss/contratos');
        $response->assertStatus(200);

        $xml = $response->getContent();
        $this->assertStringContainsString('<?xml version="1.0"', $xml);
        $this->assertStringContainsString('<rss version="2.0"', $xml);
        $this->assertStringContainsString('<item>', $xml);
        // Debe contener el objeto del contrato
        $this->assertStringContainsString('Contrato RSS', $xml);
    }

    public function test_rss_contratos_maximo_50_items(): void
    {
        $fuente = $this->createFuente();
        $organismo = $this->createOrganismo('P0000021B', 'Organismo RSS 50');

        // Crear 60 contratos con fecha
        for ($i = 1; $i <= 60; $i++) {
            Contrato::create([
                'placsp_id' => "RSS-50-{$i}",
                'url_placsp' => "https://example.com/r{$i}",
                'organismo_id' => $organismo->id,
                'fuente_datos_id' => $fuente->id,
                'version' => 1,
                'fecha_publicacion' => '2024-01-15',
            ]);
        }

        $response = $this->get('/rss/contratos');
        $response->assertStatus(200);

        $xml = $response->getContent();
        // Contar el número de <item> en el XML — debe ser ≤50
        $itemCount = substr_count($xml, '<item>');
        $this->assertLessThanOrEqual(50, $itemCount);
    }

    public function test_rss_organismo_existente_devuelve_200_xml(): void
    {
        $fuente = $this->createFuente();
        $organismo = $this->createOrganismo('P0000022C', 'Organismo Feed');

        Contrato::create([
            'placsp_id' => 'RSS-ORG-001',
            'url_placsp' => 'https://example.com/org1',
            'organismo_id' => $organismo->id,
            'fuente_datos_id' => $fuente->id,
            'version' => 1,
            'fecha_publicacion' => '2024-02-10',
            'objeto' => 'Contrato del organismo',
        ]);

        $response = $this->get("/rss/organismo/{$organismo->nif}");

        $response->assertStatus(200);
        $this->assertStringContainsString('application/rss+xml', $response->headers->get('Content-Type'));
        $xml = $response->getContent();
        $this->assertStringContainsString('<item>', $xml);
        $this->assertStringContainsString('Contrato del organismo', $xml);
    }

    public function test_rss_organismo_inexistente_devuelve_404(): void
    {
        $response = $this->get('/rss/organismo/P9999999X');

        $response->assertStatus(404);
    }
}
