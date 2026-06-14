<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FuenteDatos;
use App\Models\ImportLog;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminImportLogController extends Controller
{
    public function __invoke(Request $request): View
    {
        $query = ImportLog::with('fuenteDatos')->orderByDesc('created_at');

        if ($request->filled('fuente')) {
            $query->where('fuente_datos_id', $request->input('fuente'));
        }

        if ($request->filled('tipo')) {
            $query->where('tipo', $request->input('tipo'));
        }

        if ($request->input('solo_errores')) {
            $query->where('errores', '>', 0);
        }

        $logs = $query->paginate(50)->withQueryString();
        $fuentes = FuenteDatos::orderBy('nombre')->get();
        $tipos = ImportLog::select('tipo')->distinct()->orderBy('tipo')->pluck('tipo');

        return view('admin.import-logs', compact('logs', 'fuentes', 'tipos'));
    }
}
