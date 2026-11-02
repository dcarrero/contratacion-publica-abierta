<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exige el rol de administrador. Va detrás de `auth`: estar autenticado no basta,
 * porque el registro de cuentas está abierto a cualquiera.
 */
class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->isAdmin()) {
            abort(403, 'Acceso restringido.');
        }

        return $next($request);
    }
}
