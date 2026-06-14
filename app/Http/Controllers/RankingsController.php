<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class RankingsController extends Controller
{
    public function __invoke(): View
    {
        $rankings = null;
        if (Storage::exists('mapa-stats/rankings.json')) {
            $rankings = json_decode(Storage::get('mapa-stats/rankings.json'), true);
        }

        return view('analisis.rankings', compact('rankings'));
    }
}
