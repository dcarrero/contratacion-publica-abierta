<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Adjudicatario;
use App\Models\ComunidadAutonoma;
use App\Models\Organismo;
use App\Models\Provincia;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Sitemap XML y robots.txt para SEO. Lista las páginas de alto valor (radiografías por provincia/año,
 * informes CCAA, secciones), no las millones de fichas de empresa/organismo (se descubren por enlaces).
 *
 * De empresas y organismos sólo entran los de mayor volumen —las 618.958 empresas no caben ni
 * tienen valor de indexación en su mayoría— con su ficha y su histórico.
 */
class SitemapController extends Controller
{
    /** Cuántas empresas y organismos (por importe) entran en el sitemap. */
    private const TOP_ENTIDADES = 500;

    public function sitemap(): Response
    {
        $xml = Cache::remember('sitemap.xml', now()->addMonth(), fn () => $this->build());

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    public function robots(): Response
    {
        $body = "User-agent: *\nDisallow:\n\nSitemap: ".rtrim(config('contratacion.sitio.url'), '/')."/sitemap.xml\n";

        return response($body, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    private function build(): string
    {
        $urls = [];

        // Secciones principales.
        foreach (['dashboard', 'historico', 'contratos.index', 'organismos.index', 'empresas.index', 'mapa',
            'analisis', 'radiografia.index', 'comparar.index', 'informes.index', 'administraciones.index', 'sobre'] as $name) {
            $urls[] = [route($name), 'weekly'];
        }

        // Radiografía por provincia + años con datos (en el rango razonable).
        $years = range((int) date('Y'), 2015);
        foreach (Provincia::whereNotNull('nuts')->orderBy('nombre')->get(['nombre']) as $p) {
            $slug = Str::slug($p->nombre);
            $urls[] = [route('radiografia.show', $slug), 'monthly'];
            foreach ($years as $y) {
                $urls[] = [route('radiografia.show', ['slug' => $slug, 'year' => $y]), 'monthly'];
            }
        }

        // Informes y administraciones por CCAA, con su histórico y sus años.
        foreach (ComunidadAutonoma::whereNotNull('nuts')->orderBy('nombre')->get(['nuts']) as $ca) {
            $urls[] = [route('informes.ccaa', $ca->nuts), 'monthly'];
            $urls[] = [route('administraciones.show', $ca->nuts), 'weekly'];
            $urls[] = [route('administraciones.historico', $ca->nuts), 'monthly'];
            foreach ($years as $y) {
                $urls[] = [route('administraciones.anio', [$ca->nuts, $y]), 'monthly'];
            }
        }

        // Entidades con más volumen: ficha e histórico. El listado completo se descubre por
        // enlaces; aquí sólo van las que merecen presupuesto de rastreo.
        foreach (Organismo::orderByDesc('total_importe')->limit(self::TOP_ENTIDADES)->pluck('nif') as $nif) {
            $urls[] = [route('organismos.show', $nif), 'weekly'];
            $urls[] = [route('organismos.historico', $nif), 'monthly'];
            $urls[] = [route('organismos.red', $nif), 'monthly'];
        }

        foreach (Adjudicatario::orderByDesc('total_importe')->limit(self::TOP_ENTIDADES)->pluck('nif') as $nif) {
            $urls[] = [route('empresas.show', $nif), 'weekly'];
            $urls[] = [route('empresas.historico', $nif), 'monthly'];
            $urls[] = [route('empresas.red', $nif), 'monthly'];
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
        foreach ($urls as [$loc, $freq]) {
            $xml .= '  <url><loc>'.htmlspecialchars($loc, ENT_XML1).'</loc><changefreq>'.$freq.'</changefreq></url>'."\n";
        }
        $xml .= '</urlset>'."\n";

        return $xml;
    }
}
