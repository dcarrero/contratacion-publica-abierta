<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Provincia;
use App\Services\InformeDataBuilder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Comparador de dos provincias lado a lado (informe A6). Reutiliza buildProvincia y comparte la caché
 * mensual de la radiografía (misma clave) para no recalcular ni golpear la BD.
 */
class ComparadorController extends Controller
{
    public function index(): View
    {
        $provincias = Provincia::whereNotNull('nuts')->orderBy('nombre')
            ->get(['nombre'])
            ->map(fn (Provincia $p) => ['nombre' => $p->nombre, 'slug' => Str::slug($p->nombre)])
            ->values();

        return view('comparar.index', ['provincias' => $provincias]);
    }

    public function show(string $a, string $b, InformeDataBuilder $builder): View
    {
        $pa = $this->resolve($a);
        $pb = $this->resolve($b);

        abort_if($pa === null || $pb === null, 404);

        return view('comparar.show', [
            'pa' => $pa,
            'pb' => $pb,
            'da' => $this->datos($pa, $builder),
            'db' => $this->datos($pb, $builder),
        ]);
    }

    private function resolve(string $slug): ?Provincia
    {
        return Provincia::with('comunidadAutonoma:id,nombre')->get()
            ->first(fn (Provincia $p) => $p->nuts !== null && Str::slug($p->nombre) === $slug);
    }

    private function datos(Provincia $provincia, InformeDataBuilder $builder): array
    {
        // Misma clave que la radiografía "todos los años": comparte caché.
        return Cache::remember(
            "radiografia:{$provincia->id}:all",
            now()->addMonth(),
            fn () => $builder->buildProvincia($provincia)
        );
    }
}
