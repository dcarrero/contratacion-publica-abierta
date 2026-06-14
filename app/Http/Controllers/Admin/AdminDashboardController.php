<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Adjudicatario;
use App\Models\AlertaSuscripcion;
use App\Models\Anomalia;
use App\Models\Contrato;
use App\Models\FuenteDatos;
use App\Models\ImportLog;
use App\Models\Organismo;
use App\Support\SqlDialect;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AdminDashboardController extends Controller
{
    public function __invoke(): View
    {
        $kpis = [
            'contratos' => Contrato::count(),
            'organismos' => Organismo::count(),
            'adjudicatarios' => Adjudicatario::count(),
            'fuentes' => FuenteDatos::where('activo', true)->count(),
            'import_logs' => ImportLog::count(),
            'anomalias' => Anomalia::count(),
            'suscripciones' => AlertaSuscripcion::whereNotNull('verificada_at')->count(),
        ];

        $dbSize = $this->getDatabaseSize();
        $diskFree = disk_free_space(storage_path());

        $fuentes = FuenteDatos::where('activo', true)
            ->orderBy('ultima_sincronizacion', 'desc')
            ->get()
            ->map(function (FuenteDatos $fuente) {
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

        $erroresRecientes = ImportLog::where('errores', '>', 0)
            ->with('fuenteDatos')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        $chartsJson = storage_path('app/mapa-stats/charts.json');
        $lastStats = file_exists($chartsJson) ? filemtime($chartsJson) : null;

        return view('admin.dashboard', compact(
            'kpis',
            'dbSize',
            'diskFree',
            'fuentes',
            'erroresRecientes',
            'lastStats',
        ));
    }

    private function getDatabaseSize(): int
    {
        $connection = config('database.default');

        if ($connection === 'sqlite') {
            $path = config('database.connections.sqlite.database');

            return file_exists($path) ? (int) filesize($path) : 0;
        }

        if (SqlDialect::isPgsql()) {
            $result = DB::selectOne('SELECT pg_database_size(current_database()) AS size');

            return (int) ($result->size ?? 0);
        }

        $dbName = config("database.connections.{$connection}.database");
        $result = DB::selectOne(
            'SELECT SUM(data_length + index_length) AS size FROM information_schema.tables WHERE table_schema = ?',
            [$dbName]
        );

        return (int) ($result->size ?? 0);
    }
}
