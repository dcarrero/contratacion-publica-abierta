<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);

        // Rechaza (400) cualquier Host que no sea el de APP_URL o un subdominio suyo,
        // para que los enlaces absolutos (p. ej. el de restablecer contraseña) no
        // puedan apuntar a otro dominio. Laravel no lo aplica en local ni en los tests.
        $middleware->trustHosts();

        // Al cambiar o restablecer la contraseña, las demás sesiones y las cookies
        // «recordarme» emitidas con la contraseña anterior dejan de valer.
        $middleware->web(append: [
            \Illuminate\Session\Middleware\AuthenticateSession::class,
        ]);

        $middleware->alias([
            'admin.ip' => \App\Http\Middleware\RestrictIpAccess::class,
            'admin' => \App\Http\Middleware\EnsureUserIsAdmin::class,
        ]);

        $middleware->redirectGuestsTo('/admin/login');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
