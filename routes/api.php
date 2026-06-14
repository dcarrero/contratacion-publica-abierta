<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\ContratoApiController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — v1
|--------------------------------------------------------------------------
| Registradas con prefijo /api (via bootstrap/app.php withRouting api:).
| Rate limit: 60 peticiones/minuto por IP.
|
| Rutas de frontend interno (/api/mapa, /api/grafo) siguen en routes/web.php.
|
*/

Route::prefix('v1')->middleware('throttle:60,1')->group(function (): void {
    Route::get('/contratos', [ContratoApiController::class, 'index'])->name('api.v1.contratos.index');
});
