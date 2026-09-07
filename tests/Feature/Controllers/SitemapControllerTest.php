<?php

declare(strict_types=1);

namespace Tests\Feature\Controllers;

use App\Models\Adjudicatario;
use App\Models\Organismo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SitemapControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_sitemap_devuelve_xml_con_urls_clave(): void
    {
        $response = $this->get('/sitemap.xml');

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/xml; charset=UTF-8');
        $response->assertSee('<urlset', false);
        $response->assertSee('/radiografia/', false);
    }

    public function test_sitemap_incluye_las_paginas_de_historico(): void
    {
        $response = $this->get('/sitemap.xml');

        $response->assertStatus(200);
        $response->assertSee(route('historico'), false);
        $response->assertSee(route('administraciones.historico', 'ES42'), false);
        $response->assertSee(route('administraciones.anio', ['ES42', (int) date('Y')]), false);
    }

    public function test_sitemap_incluye_las_entidades_de_mas_volumen(): void
    {
        $grande = Organismo::create([
            'nif' => 'P4500001A',
            'nombre' => 'Organismo grande',
            'total_contratos' => 1000,
            'total_importe' => 9_000_000.0,
        ]);
        $empresaGrande = Adjudicatario::create([
            'nif' => 'B45000001',
            'nombre' => 'Empresa grande',
            'total_contratos' => 500,
            'total_importe' => 8_000_000.0,
        ]);

        $response = $this->get('/sitemap.xml');

        $response->assertStatus(200);
        $response->assertSee(route('organismos.show', $grande->nif), false);
        $response->assertSee(route('organismos.historico', $grande->nif), false);
        $response->assertSee(route('empresas.show', $empresaGrande->nif), false);
        $response->assertSee(route('empresas.historico', $empresaGrande->nif), false);
    }

    public function test_robots_referencia_el_sitemap(): void
    {
        config(['contratacion.sitio.url' => 'https://contratacionabierta.com']);

        $response = $this->get('/robots.txt');

        $response->assertStatus(200);
        $response->assertSee('User-agent: *', false);
        $response->assertSee('Sitemap: https://contratacionabierta.com/sitemap.xml', false);
    }
}
