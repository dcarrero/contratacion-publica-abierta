<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('X-XSS-Protection', '1; mode=block');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        // CSP en Report-Only: contención de segunda capa frente a XSS sin arriesgar a
        // romper nada. Cuando el navegador no reporte violaciones legítimas, promover a
        // `Content-Security-Policy` (enforcing). La allowlist refleja los CDNs realmente
        // usados: Chart.js/d3 (jsdelivr), Alpine/Leaflet (unpkg), fuentes (bunny/google),
        // tiles del mapa (OpenStreetMap).
        $response->headers->set('Content-Security-Policy-Report-Only', $this->contentSecurityPolicy());

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    private function contentSecurityPolicy(): string
    {
        return implode('; ', [
            "default-src 'self'",
            // 'unsafe-inline' necesario hoy por Livewire/Alpine y los scripts inline de las vistas.
            "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://unpkg.com",
            "style-src 'self' 'unsafe-inline' https://fonts.bunny.net https://fonts.googleapis.com",
            "font-src 'self' https://fonts.bunny.net https://fonts.gstatic.com",
            "img-src 'self' data: https://*.tile.openstreetmap.org",
            "connect-src 'self'",
            "frame-ancestors 'self'",
            "base-uri 'self'",
            "form-action 'self'",
        ]);
    }
}
