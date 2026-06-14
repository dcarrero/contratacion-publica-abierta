<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Anomalia;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AnomaliaController extends Controller
{
    public function index(Request $request): View
    {
        $query = Anomalia::with(['organismo', 'adjudicatario'])
            ->orderByDesc('created_at');

        if ($request->filled('tipo')) {
            $query->tipo($request->input('tipo'));
        }

        if ($request->filled('severidad')) {
            $query->severidad($request->input('severidad'));
        }

        $anomalias = $query->limit(100)->get();

        $stats = [
            'total' => Anomalia::count(),
            'no_revisadas' => Anomalia::noRevisada()->count(),
            'alta' => Anomalia::severidad('alta')->count(),
        ];

        return view('analisis.anomalias', [
            'anomalias' => $anomalias,
            'stats' => $stats,
            'filtro_tipo' => $request->input('tipo', ''),
            'filtro_severidad' => $request->input('severidad', ''),
        ]);
    }
}
