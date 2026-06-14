<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AdminCommandController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminDocController;
use App\Http\Controllers\Admin\AdminFuentesController;
use App\Http\Controllers\Admin\AdminImportLogController;
use App\Http\Controllers\Admin\AdminLoginController;
use App\Http\Controllers\AdministracionController;
use App\Http\Controllers\AlertaController;
use App\Http\Controllers\AnalisisController;
use App\Http\Controllers\AnomaliaController;
use App\Http\Controllers\ContratoController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmpresaController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\GrafoController;
use App\Http\Controllers\InformeController;
use App\Http\Controllers\MapaApiController;
use App\Http\Controllers\MapaController;
use App\Http\Controllers\OrganismoController;
use App\Http\Controllers\RankingsController;
use App\Http\Controllers\RssController;
use Illuminate\Support\Facades\Route;

Route::get('/', DashboardController::class)->name('dashboard');
Route::get('/contratos', [ContratoController::class, 'index'])->name('contratos.index');
Route::get('/contratos/{contrato:placsp_id}', [ContratoController::class, 'show'])->where('contrato', '.*')->name('contratos.show');
Route::get('/mapa', MapaController::class)->name('mapa');
Route::get('/api/mapa/ccaa', [MapaApiController::class, 'ccaa'])->name('api.mapa.ccaa');
Route::get('/api/mapa/provincias', [MapaApiController::class, 'provincias'])->name('api.mapa.provincias');
Route::get('/analisis', AnalisisController::class)->name('analisis');
Route::get('/administraciones', [AdministracionController::class, 'index'])->name('administraciones.index');
Route::get('/administraciones/{comunidad:nuts}', [AdministracionController::class, 'show'])->name('administraciones.show');
Route::get('/organismos', [OrganismoController::class, 'index'])->name('organismos.index');
Route::get('/organismos/{organismo:nif}', [OrganismoController::class, 'show'])->name('organismos.show');
Route::get('/empresas', [EmpresaController::class, 'index'])->name('empresas.index');
Route::get('/empresas/{adjudicatario:nif}', [EmpresaController::class, 'show'])->name('empresas.show');
Route::get('/sobre', fn () => view('sobre'))->name('sobre');
Route::get('/aviso-legal', fn () => view('aviso-legal'))->name('aviso-legal');

// Alertas y suscripciones (rate limited)
Route::middleware('throttle:10,1')->group(function (): void {
    Route::post('/alertas/suscribir', [AlertaController::class, 'suscribir'])->name('alertas.suscribir');
    Route::get('/alertas/verificar/{token}', [AlertaController::class, 'verificar'])->name('alertas.verificar');
    Route::get('/alertas/cancelar/{token}', [AlertaController::class, 'cancelar'])->name('alertas.cancelar');
});

// Análisis: rankings, anomalías y grafo
Route::get('/analisis/rankings', RankingsController::class)->name('rankings');
Route::get('/analisis/anomalias', [AnomaliaController::class, 'index'])->name('anomalias.index');
Route::get('/analisis/grafo', [GrafoController::class, 'index'])->name('grafo');
Route::get('/api/grafo/data', [GrafoController::class, 'data'])->name('grafo.data');

// Informes y exportación
Route::get('/informes', [InformeController::class, 'index'])->name('informes.index');
Route::get('/informes/ccaa/{comunidad:nuts}', [InformeController::class, 'ccaa'])->name('informes.ccaa');
Route::get('/informes/ccaa/{comunidad:nuts}/pdf', [InformeController::class, 'ccaaPdf'])->name('informes.ccaa.pdf');
Route::get('/informes/anual', [InformeController::class, 'anual'])->name('informes.anual');
Route::get('/informes/anual/pdf', [InformeController::class, 'anualPdf'])->name('informes.anual.pdf');
// Exports (rate limited: 5 descargas/minuto)
Route::middleware('throttle:5,1')->group(function (): void {
    Route::get('/export/contratos.csv', [ExportController::class, 'contratos'])->name('export.contratos');
    Route::get('/export/organismos.csv', [ExportController::class, 'organismos'])->name('export.organismos');
    Route::get('/export/adjudicatarios.csv', [ExportController::class, 'adjudicatarios'])->name('export.adjudicatarios');
});

// RSS
Route::get('/rss/contratos', [RssController::class, 'contratos'])->name('rss.contratos');
Route::get('/rss/organismo/{nif}', [RssController::class, 'organismo'])->name('rss.organismo');

// Admin
Route::prefix('admin')->middleware('admin.ip')->group(function (): void {
    Route::get('/login', [AdminLoginController::class, 'showLogin'])->name('admin.login');
    Route::post('/login', [AdminLoginController::class, 'login'])->name('admin.login.submit');

    Route::middleware('auth')->group(function (): void {
        Route::post('/logout', [AdminLoginController::class, 'logout'])->name('admin.logout');
        Route::get('/', AdminDashboardController::class)->name('admin.dashboard');
        Route::get('/import-logs', AdminImportLogController::class)->name('admin.import-logs');
        Route::get('/fuentes', AdminFuentesController::class)->name('admin.fuentes');
        Route::get('/comandos', [AdminCommandController::class, 'index'])->name('admin.commands');
        Route::post('/comandos/run', [AdminCommandController::class, 'run'])->name('admin.commands.run');
        Route::get('/docs/{page?}', AdminDocController::class)->name('admin.docs');
    });
});
