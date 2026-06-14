<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Contrato;
use Illuminate\View\View;

class ContratoController extends Controller
{
    public function index(): View
    {
        return view('contratos.index');
    }

    public function show(Contrato $contrato): View
    {
        $contrato->load(['organismo', 'adjudicatario', 'fuenteDatos', 'historial' => function ($q) {
            $q->orderByDesc('id');
        }]);

        return view('contratos.show', [
            'contrato' => $contrato,
            'tipos_contrato' => config('contratacion.tipos_contrato', []),
            'procedimientos' => config('contratacion.procedimientos', []),
        ]);
    }
}
