<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class AdminDocController extends Controller
{
    private const PAGES = [
        'index' => 'Documentación',
        'comandos' => 'Comandos Artisan',
        'arquitectura' => 'Arquitectura',
        'fuentes' => 'Fuentes de datos',
    ];

    public function __invoke(?string $page = null): View
    {
        $page = $page ?? 'index';

        if (! array_key_exists($page, self::PAGES)) {
            abort(404);
        }

        return view("admin.docs.{$page}", [
            'pages' => self::PAGES,
            'currentPage' => $page,
        ]);
    }
}
