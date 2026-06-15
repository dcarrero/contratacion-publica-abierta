<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Provincia;
use App\Services\InformeDataBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Precalienta (warming) la caché mensual de las radiografías de provincia.
 *
 * Con las 52 radiografías "todos los años" se cubre también TODO el comparador (/comparar/{a}/{b}),
 * que reutiliza la misma clave de caché por provincia — no hay que precalentar las 1.326 combinaciones.
 * Con --years precalienta además cada vista anual (más pesado).
 */
class WarmRadiografiaCache extends Command
{
    protected $signature = 'radiografia:warm {--years : precalentar también las vistas por año}';

    protected $description = 'Precalienta la caché mensual de radiografías de provincia (cubre radiografía y comparador)';

    public function handle(InformeDataBuilder $builder): int
    {
        $ttl = now()->addMonth();
        $withYears = (bool) $this->option('years');
        $count = 0;

        $provincias = Provincia::whereNotNull('nuts')->orderBy('nombre')->get();

        foreach ($provincias as $provincia) {
            $all = $builder->buildProvincia($provincia);
            Cache::put("radiografia:{$provincia->id}:all", $all, $ttl);
            $count++;

            if ($withYears) {
                foreach ($all['anios_disponibles'] as $year) {
                    Cache::put(
                        "radiografia:{$provincia->id}:{$year}",
                        $builder->buildProvincia($provincia, $year),
                        $ttl
                    );
                    $count++;
                }
            }

            $this->line("  {$provincia->nombre}: cacheada".($withYears ? ' (+ años)' : ''));
        }

        $this->info("Radiografías precalentadas: {$count}".($withYears ? ' (con vistas anuales)' : ''));

        return self::SUCCESS;
    }
}
