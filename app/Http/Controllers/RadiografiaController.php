<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Provincia;
use App\Services\InformeDataBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Radiografía de la contratación pública por provincia (informe A4): contenido público y
 * gratuito orientado a transparencia ciudadana y SEO. Gasto contratado en la provincia,
 * € por habitante (padrón INE), principales adjudicatarios y organismos.
 */
class RadiografiaController extends Controller
{
    public function index(): View
    {
        $provincias = Provincia::with('comunidadAutonoma:id,nombre')
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'nuts', 'poblacion', 'comunidad_autonoma_id'])
            ->map(fn (Provincia $p) => [
                'nombre' => $p->nombre,
                'slug' => Str::slug($p->nombre),
                'poblacion' => $p->poblacion,
                'comunidad' => $p->comunidadAutonoma?->nombre,
            ])
            ->groupBy('comunidad')
            ->sortKeys();

        return view('radiografia.index', ['provincias' => $provincias]);
    }

    public function show(string $slug, Request $request, InformeDataBuilder $builder): View
    {
        $provincia = Provincia::with('comunidadAutonoma:id,nombre')
            ->get()
            ->first(fn (Provincia $p) => Str::slug($p->nombre) === $slug);

        abort_if($provincia === null || $provincia->nuts === null, 404);

        // Año opcional (?year=2024): vista anual con comparación YoY. Validado al rango razonable.
        $year = $request->query('year');
        $year = (is_string($year) && ctype_digit($year)) ? (int) $year : null;
        if ($year !== null && ($year < 2008 || $year > (int) date('Y') + 1)) {
            $year = null;
        }

        // Caché mensual: la radiografía apenas cambia y el cálculo es pesado sobre ~8M contratos.
        // Se refresca al expirar o al limpiar la caché tras un sync grande. Clave por año.
        $data = Cache::remember(
            "radiografia:{$provincia->id}:".($year ?? 'all'),
            now()->addMonth(),
            fn () => $builder->buildProvincia($provincia, $year)
        );

        return view('radiografia.show', [
            'provincia' => $provincia,
            'slug' => $slug,
            'year' => $year,
            'data' => $data,
            'tipos' => config('contratacion.tipos_contrato', []),
        ]);
    }
}
