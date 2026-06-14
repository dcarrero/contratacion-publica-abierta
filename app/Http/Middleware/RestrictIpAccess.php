<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RestrictIpAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $allowedIps = config('contratacion.admin.allowed_ips', []);

        if (! empty($allowedIps)
            && ! in_array('*', $allowedIps, true)
            && ! in_array('0.0.0.0/0', $allowedIps, true)
            && ! in_array($request->ip(), $allowedIps, true)
        ) {
            abort(403, 'Acceso restringido.');
        }

        return $next($request);
    }
}
