<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Adjudicatario;
use App\Models\Contrato;
use App\Models\Organismo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        $kpis = Cache::remember('dashboard_kpis', 3600, function () {
            return [
                'total_contratos' => (int) Organismo::sum('total_contratos'),
                'volumen' => Organismo::sum('total_importe'),
                'total_organismos' => Organismo::count(),
                'total_adjudicatarios' => Adjudicatario::count(),
            ];
        });

        $topEmpresas = Cache::remember('dashboard_top_empresas', 3600, function () {
            return Adjudicatario::orderByDesc('total_importe')
                ->limit(10)
                ->get(['nombre', 'nif', 'total_contratos', 'total_importe']);
        });

        $topOrganismos = Cache::remember('dashboard_top_organismos', 3600, function () {
            return Organismo::orderByDesc('total_importe')
                ->limit(10)
                ->get(['nombre', 'nif', 'total_contratos', 'total_importe']);
        });

        $ultimosContratos = Contrato::with(['organismo:id,nombre,nif', 'adjudicatario:id,nombre,nif'])
            ->whereNotNull('fecha_publicacion')
            ->orderByDesc('fecha_publicacion')
            ->limit(20)
            ->get();

        $charts = null;
        if (Storage::exists('mapa-stats/charts.json')) {
            $charts = json_decode(Storage::get('mapa-stats/charts.json'), true);
        }

        return view('dashboard', compact(
            'kpis',
            'topEmpresas',
            'topOrganismos',
            'ultimosContratos',
            'charts',
        ));
    }
}
