<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ComunidadAutonoma;
use App\Models\Contrato;
use App\Models\Organismo;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AdministracionController extends Controller
{
    public function index(): View
    {
        // Leer stats pre-computadas (generadas por stats:recalculate --entity=mapa)
        $statsMap = [];
        if (Storage::exists('mapa-stats/ccaa.json')) {
            $statsData = json_decode(Storage::get('mapa-stats/ccaa.json'), true);
            foreach ($statsData as $row) {
                $statsMap[$row['nuts']] = $row;
            }
        }

        $ccaa = ComunidadAutonoma::orderBy('nombre')
            ->get()
            ->map(function (ComunidadAutonoma $ca) use ($statsMap) {
                $stats = $statsMap[$ca->nuts] ?? null;
                $ca->stats_contratos = $stats ? (int) $stats['total_contratos'] : 0;
                $ca->stats_importe = $stats ? (float) $stats['total_importe'] : 0.0;
                $ca->stats_per_capita = $stats['gasto_per_capita'] ?? null;
                $ca->stats_organismos = 0;

                // Organismos count: usar pre-computado si existe
                $orgFile = "mapa-stats/admin-{$ca->nuts}.json";
                if (Storage::exists($orgFile)) {
                    $adminData = json_decode(Storage::get($orgFile), true);
                    $ca->stats_organismos = $adminData['total_organismos'] ?? 0;
                }

                return $ca;
            })
            ->sortByDesc('stats_importe')
            ->values();

        return view('administraciones.index', [
            'ccaa' => $ccaa,
        ]);
    }

    public function show(ComunidadAutonoma $comunidad): View
    {
        $adminFile = "mapa-stats/admin-{$comunidad->nuts}.json";

        if (Storage::exists($adminFile)) {
            $cached = json_decode(Storage::get($adminFile), true);

            $topOrganismos = Organismo::whereIn('id', $cached['top_organismos_ids'] ?? [])
                ->orderByDesc('total_importe')
                ->get();

            $ultimosContratos = Contrato::with(['organismo:id,nombre,nif', 'adjudicatario:id,nombre,nif'])
                ->where('nuts', 'LIKE', "{$comunidad->nuts}%")
                ->orderByDesc('fecha_publicacion')
                ->limit(10)
                ->get();

            $data = [
                'total_contratos' => $cached['total_contratos'],
                'total_importe' => $cached['total_importe'],
                'total_organismos' => $cached['total_organismos'],
                'total_adjudicatarios' => $cached['total_adjudicatarios'],
                'top_organismos' => $topOrganismos,
                'ultimos_contratos' => $ultimosContratos,
            ];
        } else {
            // Fallback: query directa (lento en SQLite con 7M+ registros)
            $stats = Contrato::where('nuts', 'LIKE', "{$comunidad->nuts}%")
                ->selectRaw('COUNT(*) as total_contratos, COALESCE(SUM(importe_adjudicacion), 0) as total_importe')
                ->first();

            $topOrganismos = Organismo::whereHas('contratos', function ($q) use ($comunidad) {
                $q->where('nuts', 'LIKE', "{$comunidad->nuts}%");
            })
                ->where('total_contratos', '>', 0)
                ->orderByDesc('total_importe')
                ->limit(20)
                ->get();

            $ultimosContratos = Contrato::with(['organismo:id,nombre,nif', 'adjudicatario:id,nombre,nif'])
                ->where('nuts', 'LIKE', "{$comunidad->nuts}%")
                ->orderByDesc('fecha_publicacion')
                ->limit(10)
                ->get();

            $totalOrganismos = Organismo::whereHas('contratos', function ($q) use ($comunidad) {
                $q->where('nuts', 'LIKE', "{$comunidad->nuts}%");
            })->count();

            $totalAdjudicatarios = DB::table('adjudicatarios')
                ->whereExists(function ($query) use ($comunidad) {
                    $query->select(DB::raw(1))
                        ->from('contratos')
                        ->whereColumn('contratos.adjudicatario_id', 'adjudicatarios.id')
                        ->where('contratos.nuts', 'LIKE', "{$comunidad->nuts}%");
                })
                ->count();

            $data = [
                'total_contratos' => (int) $stats->total_contratos,
                'total_importe' => (float) $stats->total_importe,
                'total_organismos' => $totalOrganismos,
                'total_adjudicatarios' => $totalAdjudicatarios,
                'top_organismos' => $topOrganismos,
                'ultimos_contratos' => $ultimosContratos,
            ];
        }

        return view('administraciones.show', [
            'comunidad' => $comunidad,
            ...$data,
        ]);
    }
}
