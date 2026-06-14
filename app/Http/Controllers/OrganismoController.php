<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Contrato;
use App\Models\Organismo;
use App\Support\SqlDialect;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class OrganismoController extends Controller
{
    public function index(): View
    {
        return view('organismos.index');
    }

    public function show(Organismo $organismo): View
    {
        $cacheKey = "organismo:{$organismo->id}:ficha";

        $ficha = Cache::remember($cacheKey, 3600, function () use ($organismo) {
            $yearExpr = SqlDialect::year('fecha_publicacion');

            $agg = Contrato::forOrganismo($organismo->id)
                ->selectRaw(
                    'COUNT(*) as total, '
                    .'SUM(importe_adjudicacion) as importe_total, '
                    .'COUNT(DISTINCT adjudicatario_id) as adjudicatarios_distintos, '
                    .SqlDialect::sumBool('es_menor').' as total_menores'
                )
                ->first();

            $total = (int) $agg->total;

            return [
                'kpis' => [
                    'total_contratos' => $total,
                    'importe_total' => $agg->importe_total ?? 0,
                    'adjudicatarios_distintos' => (int) $agg->adjudicatarios_distintos,
                    'pct_menores' => $total > 0 ? round((int) $agg->total_menores / $total * 100, 1) : 0,
                ],
                'top_adjudicatarios' => Contrato::forOrganismo($organismo->id)
                    ->select('adjudicatario_id', DB::raw('COUNT(*) as num_contratos'), DB::raw('SUM(importe_adjudicacion) as total_importe'))
                    ->whereNotNull('adjudicatario_id')
                    ->groupBy('adjudicatario_id')
                    ->orderByDesc('total_importe')
                    ->limit(10)
                    ->with('adjudicatario:id,nombre,nif')
                    ->get(),
                'distribucion_tipo' => Contrato::forOrganismo($organismo->id)
                    ->select('tipo_contrato', DB::raw('COUNT(*) as num_contratos'), DB::raw('SUM(importe_adjudicacion) as total_importe'))
                    ->whereNotNull('tipo_contrato')
                    ->groupBy('tipo_contrato')
                    ->orderByDesc('total_importe')
                    ->get(),
                'evolucion_anual' => Contrato::forOrganismo($organismo->id)
                    ->selectRaw("{$yearExpr} as year, COUNT(*) as num_contratos, SUM(importe_adjudicacion) as total_importe")
                    ->whereNotNull('fecha_publicacion')
                    ->groupByRaw($yearExpr)
                    ->orderByDesc('year')
                    ->get(),
            ];
        });

        $ultimosContratos = Contrato::forOrganismo($organismo->id)
            ->with('adjudicatario:id,nombre,nif')
            ->orderByDesc('fecha_publicacion')
            ->paginate(10);

        return view('organismos.show', [
            'organismo' => $organismo,
            'ficha' => $ficha,
            'ultimosContratos' => $ultimosContratos,
            'tipos_contrato' => config('contratacion.tipos_contrato', []),
        ]);
    }
}
