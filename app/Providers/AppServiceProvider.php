<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Misma política en registro, cambio y restablecimiento de contraseña.
        // La consulta a Have I Been Pwned (k-anonimato) solo en producción, para que
        // los tests y el desarrollo no dependan de la red.
        Password::defaults(function (): Password {
            $rule = Password::min(12)->max(255)->letters()->numbers();

            return $this->app->environment('production') ? $rule->uncompromised() : $rule;
        });

        // Con APP_URL en https, los enlaces absolutos salen siempre en https aunque la
        // petición llegue por http (p. ej. detrás de un proxy).
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }
    }
}
