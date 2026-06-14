<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Adjudicatario;
use App\Models\Contrato;
use App\Support\SqlDialect;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class EmpresaController extends Controller
{
    public function index(): View
    {
        return view('empresas.index');
    }

    public function show(Adjudicatario $adjudicatario): View
    {
        $cacheKey = "empresa:{$adjudicatario->id}:ficha";

        $ficha = Cache::remember($cacheKey, 3600, function () use ($adjudicatario) {
            $yearExpr = SqlDialect::year('fecha_publicacion');

            $agg = Contrato::forAdjudicatario($adjudicatario->id)
                ->selectRaw(
                    'COUNT(*) as total, '
                    .'SUM(importe_adjudicacion) as importe_total, '
                    .'COUNT(DISTINCT organismo_id) as organismos_distintos, '
                    .'MAX(importe_adjudicacion) as contrato_mayor'
                )
                ->first();

            return [
                'kpis' => [
                    'total_contratos' => (int) $agg->total,
                    'importe_total' => $agg->importe_total ?? 0,
                    'organismos_distintos' => (int) $agg->organismos_distintos,
                    'contrato_mayor' => $agg->contrato_mayor,
                ],
                'aliases' => $adjudicatario->aliases()
                    ->orderByDesc('veces_visto')
                    ->get(),
                'top_organismos' => Contrato::forAdjudicatario($adjudicatario->id)
                    ->select('organismo_id', DB::raw('COUNT(*) as num_contratos'), DB::raw('SUM(importe_adjudicacion) as total_importe'))
                    ->whereNotNull('organismo_id')
                    ->groupBy('organismo_id')
                    ->orderByDesc('total_importe')
                    ->limit(10)
                    ->with('organismo:id,nombre,nif')
                    ->get(),
                'distribucion_tipo' => Contrato::forAdjudicatario($adjudicatario->id)
                    ->select('tipo_contrato', DB::raw('COUNT(*) as num_contratos'), DB::raw('SUM(importe_adjudicacion) as total_importe'))
                    ->whereNotNull('tipo_contrato')
                    ->groupBy('tipo_contrato')
                    ->orderByDesc('total_importe')
                    ->get(),
                'evolucion_anual' => Contrato::forAdjudicatario($adjudicatario->id)
                    ->selectRaw("{$yearExpr} as year, COUNT(*) as num_contratos, SUM(importe_adjudicacion) as total_importe")
                    ->whereNotNull('fecha_publicacion')
                    ->groupByRaw($yearExpr)
                    ->orderByDesc('year')
                    ->get(),
            ];
        });

        $contratos = Contrato::forAdjudicatario($adjudicatario->id)
            ->with('organismo:id,nombre,nif')
            ->orderByDesc('fecha_publicacion')
            ->paginate(10);

        return view('empresas.show', [
            'empresa' => $adjudicatario,
            'ficha' => $ficha,
            'contratos' => $contratos,
            'tipos_contrato' => config('contratacion.tipos_contrato', []),
        ]);
    }
}
