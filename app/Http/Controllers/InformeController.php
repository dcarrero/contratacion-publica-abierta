<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ComunidadAutonoma;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class InformeController extends Controller
{
    public function index(): View
    {
        $ccaaStats = [];
        if (Storage::exists('mapa-stats/ccaa.json')) {
            $ccaaStats = json_decode(Storage::get('mapa-stats/ccaa.json'), true) ?? [];
        }

        $ccaaList = ComunidadAutonoma::orderBy('nombre')->get();

        // Años disponibles para informe nacional
        $currentYear = (int) now()->format('Y');
        $years = range($currentYear - 1, 2018, -1);

        return view('informes.index', compact('ccaaStats', 'ccaaList', 'years'));
    }

    public function ccaa(ComunidadAutonoma $comunidad): View
    {
        $file = "mapa-stats/informe-{$comunidad->nuts}.json";
        $data = Storage::exists($file)
            ? json_decode(Storage::get($file), true)
            : null;

        return view('informes.ccaa', [
            'comunidad' => $comunidad,
            'data' => $data,
            'tipos' => config('contratacion.tipos_contrato', []),
        ]);
    }

    public function ccaaPdf(ComunidadAutonoma $comunidad): Response
    {
        $file = "mapa-stats/informe-{$comunidad->nuts}.json";
        $data = Storage::exists($file)
            ? json_decode(Storage::get($file), true)
            : null;

        $pdf = Pdf::loadView('informes.pdf.ccaa', [
            'comunidad' => $comunidad,
            'data' => $data,
            'tipos' => config('contratacion.tipos_contrato', []),
        ]);

        $pdf->setPaper('A4', 'portrait');

        $filename = 'informe-'.mb_strtolower(str_replace(' ', '-', $comunidad->nombre)).'.pdf';

        return $pdf->download($filename);
    }

    public function anual(Request $request): View
    {
        $year = (int) ($request->query('year') ?: now()->subYear()->format('Y'));

        $file = "mapa-stats/informe-nacional-{$year}.json";
        $data = Storage::exists($file)
            ? json_decode(Storage::get($file), true)
            : null;

        $currentYear = (int) now()->format('Y');
        $years = range($currentYear - 1, 2018, -1);

        return view('informes.anual', [
            'data' => $data,
            'year' => $year,
            'years' => $years,
            'tipos' => config('contratacion.tipos_contrato', []),
        ]);
    }

    public function anualPdf(Request $request): Response
    {
        $year = (int) ($request->query('year') ?: now()->subYear()->format('Y'));

        $file = "mapa-stats/informe-nacional-{$year}.json";
        $data = Storage::exists($file)
            ? json_decode(Storage::get($file), true)
            : null;

        $pdf = Pdf::loadView('informes.pdf.anual', [
            'data' => $data,
            'year' => $year,
            'tipos' => config('contratacion.tipos_contrato', []),
        ]);

        $pdf->setPaper('A4', 'portrait');

        return $pdf->download("informe-nacional-{$year}.pdf");
    }
}
