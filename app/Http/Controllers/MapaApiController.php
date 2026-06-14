<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class MapaApiController extends Controller
{
    public function ccaa(): JsonResponse
    {
        $path = 'mapa-stats/ccaa.json';

        if (! Storage::exists($path)) {
            return response()->json([]);
        }

        $data = json_decode(Storage::get($path), true);

        return response()->json($data);
    }

    public function provincias(Request $request): JsonResponse
    {
        $ccaa = $request->query('ccaa', '');

        if (! $ccaa || ! preg_match('/^ES\d{2}$/', $ccaa)) {
            return response()->json(['error' => 'Parámetro ccaa requerido (ej: ES42)'], 400);
        }

        $path = "mapa-stats/provincias-{$ccaa}.json";

        if (! Storage::exists($path)) {
            return response()->json([]);
        }

        $data = json_decode(Storage::get($path), true);

        return response()->json($data);
    }
}
