<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

$pg = DB::connection('pgsql');
$my = DB::connection('mariadb');

// contratos FIRST (historial has FK to contratos, CASCADE would wipe it)
$tables = ['contratos', 'contratos_historial'];

foreach ($tables as $table) {
    $myCount = $my->table($table)->count();
    $pgCount = $pg->table($table)->count();

    echo "{$table}: MariaDB={$myCount}, PG={$pgCount}\n";

    if ($pgCount >= $myCount) {
        echo "  Already complete, skipping.\n\n";

        continue;
    }

    // Get column info
    $columns = $my->select("
        SELECT COLUMN_NAME, DATA_TYPE
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = 'contratacion' AND TABLE_NAME = ?
        ORDER BY ORDINAL_POSITION
    ", [$table]);

    $colNames = array_map(fn ($c) => $c->COLUMN_NAME, $columns);
    $colList = implode(',', $colNames);
    $boolCols = ['es_menor', 'es_clm', 'es_pyme', 'activo', 'revisada', 'activa'];

    // Resume or fresh start
    $pg->statement('SET session_replication_role = replica');
    $resumeFromId = 0;
    if ($pgCount > 0) {
        $resumeFromId = (int) $pg->table($table)->max('id');
        echo "  Resuming from id={$resumeFromId} ({$pgCount} already in PG)...\n";
    } else {
        $pg->statement("TRUNCATE TABLE {$table} CASCADE");
    }

    $remaining = $myCount - $pgCount;
    echo "  Importing {$remaining} remaining rows...\n";
    $startTime = microtime(true);

    $pdo = $pg->getPdo();
    $copied = $pgCount;
    $lastId = $resumeFromId;
    $chunkSize = 5000;

    while (true) {
        $rows = $my->select(
            "SELECT {$colList} FROM {$table} WHERE id > ? ORDER BY id LIMIT ?",
            [$lastId, $chunkSize]
        );

        if (empty($rows)) {
            break;
        }

        // Build COPY-compatible lines
        $numericCols = ['importe_licitacion', 'importe_licitacion_con_iva', 'importe_estimado',
            'importe_adjudicacion', 'importe_adjudicacion_con_iva', 'num_ofertas', 'version',
            'organismo_id', 'adjudicatario_id', 'fuente_datos_id', 'contrato_id', 'poblacion',
            'total_contratos', 'total_importe', 'total_adjudicatarios', 'total_menores'];
        $dateCols = ['fecha_publicacion', 'fecha_limite', 'fecha_adjudicacion',
            'fecha_formalizacion', 'fecha_updated', 'created_at', 'updated_at'];
        $lines = [];
        foreach ($rows as $row) {
            $values = [];
            foreach ($colNames as $col) {
                $val = $row->{$col};
                if ($val === null || $val === '') {
                    $values[] = '\\N';
                } elseif (in_array($col, $boolCols)) {
                    $values[] = $val ? 't' : 'f';
                } elseif (in_array($col, $dateCols) && str_starts_with((string) $val, '0000')) {
                    $values[] = '\\N'; // MySQL zero date → NULL
                } elseif (in_array($col, $numericCols) && ! is_numeric($val)) {
                    $values[] = '\\N'; // Non-numeric in numeric column → NULL
                } else {
                    $val = str_replace(
                        ['\\', "\t", "\n", "\r"],
                        ['\\\\', ' ', ' ', ''],
                        (string) $val
                    );
                    $values[] = $val;
                }
            }
            $lines[] = implode("\t", $values);
        }

        $nullMarker = '__NULL__';
        $lines = array_map(fn ($l) => str_replace('\\N', $nullMarker, $l), $lines);
        try {
            $pdo->pgsqlCopyFromArray($table, $lines, "\t", $nullMarker, $colList);
        } catch (\PDOException $e) {
            // On error, fall back to row-by-row insert (skip duplicates/invalid)
            $skipped = 0;
            foreach ($rows as $singleRow) {
                try {
                    $vals = [];
                    foreach ($colNames as $col) {
                        $v = $singleRow->{$col};
                        if ($v === null || $v === '') {
                            $vals[$col] = null;
                        } elseif (in_array($col, $dateCols) && str_starts_with((string) $v, '0000')) {
                            $vals[$col] = null;
                        } elseif (in_array($col, $boolCols)) {
                            $vals[$col] = (bool) $v;
                        } else {
                            $vals[$col] = $v;
                        }
                    }
                    $pg->table($table)->insert($vals);
                } catch (\Throwable) {
                    $skipped++;
                }
            }
            if ($skipped > 0) {
                echo "[{$skipped} skipped in batch] ";
            }
        }

        $lastId = $rows[count($rows) - 1]->id;
        $copied += count($rows);

        if ($copied % 50000 === 0) {
            $elapsed = microtime(true) - $startTime;
            $rate = round($copied / $elapsed);
            $eta = round(($myCount - $copied) / max($rate, 1) / 60, 1);
            echo "  {$copied}/{$myCount} (".round($copied / $myCount * 100, 1)."%) - {$rate}/s - ETA {$eta}min\n";
        }

        // Free memory
        unset($rows, $lines);
    }

    // Reset sequence
    $maxId = $pg->table($table)->max('id');
    if ($maxId) {
        $seqName = $pg->selectOne("SELECT pg_get_serial_sequence(?, 'id') as seq", [$table]);
        if ($seqName && $seqName->seq) {
            $pg->statement("SELECT setval('{$seqName->seq}', ?)", [$maxId]);
        }
    }

    $pg->statement('SET session_replication_role = DEFAULT');

    $elapsed = round(microtime(true) - $startTime, 1);
    $pgFinal = $pg->table($table)->count();
    $match = $pgFinal === $myCount ? 'OK' : "DIFF! PG={$pgFinal}";
    echo "  Done: {$pgFinal}/{$myCount} in {$elapsed}s - {$match}\n\n";
}

echo "=== Verificación final ===\n";
foreach ($tables as $table) {
    $myCount = $my->table($table)->count();
    $pgCount = $pg->table($table)->count();
    $status = $myCount === $pgCount ? 'OK' : 'DIFF!';
    echo "  {$table}: MariaDB={$myCount} PG={$pgCount} {$status}\n";
}
