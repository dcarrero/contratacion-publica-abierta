<?php

declare(strict_types=1);

namespace Tests\Feature\Controllers;

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

    public function test_robots_referencia_el_sitemap(): void
    {
        config(['contratacion.sitio.url' => 'https://contratacionabierta.com']);

        $response = $this->get('/robots.txt');

        $response->assertStatus(200);
        $response->assertSee('User-agent: *', false);
        $response->assertSee('Sitemap: https://contratacionabierta.com/sitemap.xml', false);
    }
}
