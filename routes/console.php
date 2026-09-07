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

// Feed vivo de plataformas agregadas (sindicación 1044: Cataluña, Euskadi, Andalucía,
// Madrid, Galicia, Navarra, La Rioja). Es un canal disjunto del 643, por eso va además
// de placsp:sync-licitaciones. PLACSP regenera el feed sobre las 03:00.
Schedule::command('regional:sync-agregadas')
    ->dailyAt('04:20')
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

// === Ayuntamiento de Madrid — contratos menores (mensual) ===
// El portal declara periodicidad mensual (desfase real observado ~6 semanas), así que
// no tiene sentido bajarlo a diario. Se reimportan el año en curso y el anterior: el
// fichero del año N contiene contratos adjudicados en N-1 e inscritos en N.

Schedule::command("regional:sync-mad-ayto --year={$currentYear} --year={$prevYear}")
    ->monthlyOn(15, '02:30')
    ->withoutOverlapping()
    ->appendOutputTo($syncLog);

// === Mantenimiento de calidad de datos y normalización (mensual) ===
// data:fix-quality NULL-ea fechas fuera de rango (año <1900 o futuro imposible) en
// las 4 columnas de fecha, además de otras correcciones idempotentes. Mensual de momento
// (los syncs apenas introducen fechas basura nuevas); subir a semanal/diario si reaparecen.

Schedule::command('data:fix-quality')
    ->monthlyOn(1, '06:30')  // Día 1 del mes, 06:30 (tras los syncs)
    ->withoutOverlapping()
    ->appendOutputTo($syncLog);


// === Stats y análisis (diario, tras syncs) ===

Schedule::command('stats:recalculate')
    ->dailyAt('07:00')
    ->appendOutputTo($syncLog);

// Precalienta la caché mensual de radiografías de provincia (cubre también todo el comparador,
// que reutiliza la misma caché por provincia). Mensual, tras stats, en segundo plano.
Schedule::command('radiografia:warm --years')
    ->monthlyOn(1, '07:30')
    ->withoutOverlapping()
    ->runInBackground()
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
