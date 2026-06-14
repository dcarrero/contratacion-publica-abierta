<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled Tasks — Sincronización diaria de datos
|--------------------------------------------------------------------------
|
| Todas las fuentes se sincronizan de forma incremental:
| - PLACSP feeds: el sync incremental solo descarga entries nuevos
| - Regionales con --year: solo año actual (datos antiguos no cambian)
| - Catalunya: incremental automático por $where updated_at > last_sync
|
| Orden: feeds nacionales → regionales → stats → alertas
|
*/

$syncLog = storage_path('logs/placsp-sync.log');
$currentYear = date('Y');
$prevYear = (string) ((int) $currentYear - 1);

// === PLACSP Nacional (diario, incremental) ===

Schedule::command('placsp:sync-licitaciones')
    ->dailyAt('04:00')
    ->withoutOverlapping()
    ->appendOutputTo($syncLog);

Schedule::command('placsp:sync-menores')
    ->dailyAt('04:15')
    ->withoutOverlapping()
    ->appendOutputTo($syncLog);

Schedule::command('regional:sync-mad')
    ->dailyAt('04:30')
    ->withoutOverlapping()
    ->appendOutputTo($syncLog);

// === PLACSP Agregación (semanal, ZIP anual del año en curso) ===

Schedule::command("placsp:import-zip agregacion {$currentYear} --keep-zips")
    ->weeklyOn(1, '02:00')  // Lunes 02:00
    ->withoutOverlapping()
    ->appendOutputTo($syncLog);

Schedule::command("placsp:import-zip emp {$currentYear} --keep-zips")
    ->weeklyOn(1, '03:00')  // Lunes 03:00
    ->withoutOverlapping()
    ->appendOutputTo($syncLog);

// === Regionales (semanal, solo año actual) ===

Schedule::command('regional:sync-cat')
    ->weeklyOn(2, '02:00')  // Martes 02:00 (incremental automático)
    ->withoutOverlapping()
    ->appendOutputTo($syncLog)
    ->runInBackground();

Schedule::command("regional:sync-eusk --year={$currentYear}")
    ->weeklyOn(3, '02:00')  // Miércoles 02:00
    ->withoutOverlapping()
    ->appendOutputTo($syncLog);

Schedule::command("regional:enrich-eusk --year={$currentYear} --concurrency=5")
    ->weeklyOn(3, '05:00')  // Miércoles 05:00 (tras sync)
    ->withoutOverlapping()
    ->appendOutputTo($syncLog);

Schedule::command('regional:sync-cyl')
    ->weeklyOn(4, '02:00')  // Jueves 02:00
    ->withoutOverlapping()
    ->appendOutputTo($syncLog);

Schedule::command("regional:sync-anda --year={$currentYear} --year={$prevYear}")
    ->weeklyOn(5, '02:00')  // Viernes 02:00 (año actual + anterior por retraso CKAN)
    ->withoutOverlapping()
    ->appendOutputTo($syncLog);

Schedule::command("regional:sync-val --year={$currentYear}")
    ->weeklyOn(5, '03:00')  // Viernes 03:00
    ->withoutOverlapping()
    ->appendOutputTo($syncLog);

Schedule::command('regional:sync-can')
    ->weeklyOn(5, '04:00')  // Viernes 04:00
    ->withoutOverlapping()
    ->appendOutputTo($syncLog);

Schedule::command("regional:sync-ara --year={$currentYear}")
    ->weeklyOn(6, '02:00')  // Sábado 02:00
    ->withoutOverlapping()
    ->appendOutputTo($syncLog);

Schedule::command('regional:sync-ast')
    ->weeklyOn(6, '03:00')  // Sábado 03:00
    ->withoutOverlapping()
    ->appendOutputTo($syncLog);

// === Normalización de adjudicatarios (DESACTIVADO) ===
// nif:merge-masked NO se programa automáticamente: el tier de match por dígitos
// produce falsos positivos (fusiona personas/empresas distintas con nombre similar).
// Ejecutar SOLO manualmente con --dry-run y revisar antes de aplicar. Reactivar
// únicamente cuando el matcher esté endurecido (solo empresas + apellidos completos).

// === Stats y análisis (diario, tras syncs) ===

Schedule::command('stats:recalculate')
    ->dailyAt('07:00')
    ->appendOutputTo($syncLog);

// === Anomalías y alertas ===

Schedule::command('anomalias:detectar')
    ->weeklyOn(1, '06:00')  // Lunes 06:00
    ->withoutOverlapping()
    ->appendOutputTo($syncLog);

Schedule::command('alertas:enviar --frecuencia=diaria')
    ->dailyAt('07:30')
    ->withoutOverlapping()
    ->appendOutputTo($syncLog);

Schedule::command('alertas:enviar --frecuencia=semanal')
    ->weeklyOn(1, '08:00')  // Lunes 08:00
    ->withoutOverlapping()
    ->appendOutputTo($syncLog);
