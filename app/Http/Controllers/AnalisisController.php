<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class AnalisisController extends Controller
{
    public function __invoke(): View
    {
        $charts = null;
        if (Storage::exists('mapa-stats/charts.json')) {
            $charts = json_decode(Storage::get('mapa-stats/charts.json'), true);
        }

        return view('analisis', compact('charts'));
    }
}
