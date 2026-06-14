<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

$pg = DB::connection('pgsql');
$my = DB::connection('mariadb');

// Disable FK checks in PostgreSQL
$pg->statement('SET session_replication_role = replica');

// Tables in dependency order (small tables first)
$tables = [
    'comunidades_autonomas',
    'provincias',
    'fuentes_datos',
    'organismos',
    'adjudicatarios',
    'adjudicatario_aliases',
    'import_logs',
    'anomalias',
    'alertas_suscripciones',
    'contratos_historial',
    'contratos',
];

foreach ($tables as $table) {
    $total = $my->table($table)->count();
    echo "{$table}: {$total} rows to copy... ";

    // Truncate (cascade for FK)
    $pg->statement("TRUNCATE TABLE {$table} CASCADE");

    if ($total === 0) {
        echo "SKIP (empty)\n";

        continue;
    }

    $copied = 0;
    $chunkSize = ($table === 'contratos' || $table === 'contratos_historial') ? 5000 : 2000;

    $my->table($table)->orderBy('id')->chunk($chunkSize, function ($rows) use ($pg, $table, &$copied, $total) {
        $data = $rows->map(fn ($r) => (array) $r)->toArray();

        // PostgreSQL boolean fix: convert 0/1 to false/true for boolean columns
        if (in_array($table, ['anomalias', 'alertas_suscripciones'])) {
            foreach ($data as &$row) {
                if (isset($row['revisada'])) {
                    $row['revisada'] = (bool) $row['revisada'];
                }
                if (isset($row['activa'])) {
                    $row['activa'] = (bool) $row['activa'];
                }
            }
            unset($row);
        }

        if (in_array($table, ['organismos'])) {
            foreach ($data as &$row) {
                if (isset($row['activo'])) {
                    $row['activo'] = (bool) $row['activo'];
                }
            }
            unset($row);
        }

        if (in_array($table, ['fuentes_datos'])) {
            foreach ($data as &$row) {
                if (isset($row['activo'])) {
                    $row['activo'] = (bool) $row['activo'];
                }
            }
            unset($row);
        }

        if (in_array($table, ['contratos'])) {
            foreach ($data as &$row) {
                if (isset($row['es_clm'])) {
                    $row['es_clm'] = (bool) $row['es_clm'];
                }
                if (isset($row['es_menor'])) {
                    $row['es_menor'] = (bool) $row['es_menor'];
                }
                if (isset($row['es_pyme'])) {
                    $row['es_pyme'] = $row['es_pyme'] === null ? null : (bool) $row['es_pyme'];
                }
            }
            unset($row);
        }

        try {
            $pg->table($table)->insert($data);
        } catch (\Throwable $e) {
            // Try row by row on error
            $errors = 0;
            foreach ($data as $singleRow) {
                try {
                    $pg->table($table)->insert($singleRow);
                } catch (\Throwable $e2) {
                    $errors++;
                }
            }
            if ($errors > 0) {
                echo "[{$errors} errors] ";
            }
        }

        $copied += count($rows);

        if ($copied % 50000 === 0 || $copied === $total) {
            $pct = round($copied / $total * 100, 1);
            echo "\r{$table}: {$copied}/{$total} ({$pct}%)... ";
        }
    });

    // Reset sequence
    $maxId = $pg->table($table)->max('id');
    if ($maxId) {
        $seqName = $pg->selectOne(
            "SELECT pg_get_serial_sequence(?, 'id') as seq",
            [$table]
        );
        if ($seqName && $seqName->seq) {
            $pg->statement("SELECT setval('{$seqName->seq}', ?)", [$maxId]);
        }
    }

    $pgCount = $pg->table($table)->count();
    echo "\r{$table}: {$pgCount}/{$total} copied".($pgCount < $total ? ' (DIFF!)' : '')."\n";
}

// Re-enable FK checks
$pg->statement('SET session_replication_role = DEFAULT');

echo "\n=== Verificación ===\n";
foreach ($tables as $table) {
    $myCount = $my->table($table)->count();
    $pgCount = $pg->table($table)->count();
    $status = $myCount === $pgCount ? '✓' : '✗ DIFF';
    echo "{$table}: MariaDB={$myCount} PG={$pgCount} {$status}\n";
}
