<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Índices compuestos (organismo_id, fecha_publicacion) y (adjudicatario_id, fecha_publicacion) para
 * acelerar el listado paginado "últimos contratos" de las fichas de organismo y empresa.
 *
 * Sin ellos, `WHERE organismo_id = ? ORDER BY fecha_publicacion DESC LIMIT 10` ordena cientos de miles
 * de filas (12s+ en organismos grandes como el SAS). Con el índice compuesto es una lectura de rango.
 * Solo PostgreSQL; en SQLite (tests) no es necesario.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS contratos_organismo_fecha_index ON contratos (organismo_id, fecha_publicacion DESC)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS contratos_adjudicatario_fecha_index ON contratos (adjudicatario_id, fecha_publicacion DESC)');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS contratos_organismo_fecha_index');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS contratos_adjudicatario_fecha_index');
    }
};
