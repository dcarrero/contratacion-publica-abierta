<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ComunidadAutonoma;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class GrafoController extends Controller
{
    public function index(): View
    {
        $ccaaList = ComunidadAutonoma::orderBy('nombre')->get();

        return view('analisis.grafo', compact('ccaaList'));
    }

    public function data(Request $request): JsonResponse
    {
        $ccaa = $request->query('ccaa');

        if ($ccaa && $ccaa !== 'nacional') {
            if (! preg_match('/^ES\d{2}$/', $ccaa)) {
                return response()->json(['nodes' => [], 'links' => [], 'error' => 'CCAA no válida']);
            }
            $file = "mapa-stats/grafo-{$ccaa}.json";
        } else {
            $file = 'mapa-stats/grafo-nacional.json';
        }

        if (! Storage::exists($file)) {
            return response()->json(['nodes' => [], 'links' => [], 'error' => 'Datos no disponibles. Ejecuta: php artisan stats:recalculate --entity=grafo']);
        }

        $data = json_decode(Storage::get($file), true);

        return response()->json($data);
    }
}
