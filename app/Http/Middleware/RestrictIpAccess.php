<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lista de IP permitidas para el panel. Falla cerrado: sin lista configurada no
 * entra nadie. Admite IP sueltas y rangos CIDR (IPv4 e IPv6); abrir a todo el
 * mundo exige escribirlo a propósito (`0.0.0.0/0,::/0`), como en desarrollo.
 */
class RestrictIpAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $allowed = array_values(array_filter(array_map(
            'trim',
            (array) config('contratacion.admin.allowed_ips', [])
        )));

        $ip = $request->ip();

        if ($allowed === [] || $ip === null || ! IpUtils::checkIp($ip, $allowed)) {
            abort(403, 'Acceso restringido.');
        }

        return $next($request);
    }
}
