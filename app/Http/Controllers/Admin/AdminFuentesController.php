<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Contrato;
use App\Models\FuenteDatos;
use App\Models\ImportLog;
use Illuminate\View\View;

class AdminFuentesController extends Controller
{
    public function __invoke(): View
    {
        $fuentes = FuenteDatos::orderBy('nombre')->get()->map(function (FuenteDatos $fuente) {
            $fuente->total_contratos = Contrato::where('fuente_datos_id', $fuente->id)->count();
            $fuente->ultimo_log = ImportLog::where('fuente_datos_id', $fuente->id)
                ->orderByDesc('created_at')
                ->first();

            $dias = $fuente->ultima_sincronizacion
                ? (int) $fuente->ultima_sincronizacion->diffInDays(now())
                : null;

            $fuente->semaforo = match (true) {
                $dias === null => 'gris',
                $dias < 7 => 'verde',
                $dias < 14 => 'amarillo',
                default => 'rojo',
            };

            return $fuente;
        });

        return view('admin.fuentes', compact('fuentes'));
    }
}
