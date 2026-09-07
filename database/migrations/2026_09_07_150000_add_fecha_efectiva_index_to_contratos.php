<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Índice de expresión para el orden por defecto del buscador de contratos.
 *
 * `ContratoBuscador` ordena por `COALESCE(fecha_publicacion, fecha_adjudicacion,
 * fecha_formalizacion)` —la mejor fecha disponible de cada contrato—, y ninguna de las tres
 * columnas indexadas por separado sirve para eso: PostgreSQL resolvía el orden con un
 * Parallel Seq Scan de los 8,8M de contratos más un sort, 1,4 s para devolver diez filas.
 *
 * El índice replica la expresión exacta del scope, incluido `DESC NULLS LAST`: si el orden
 * del índice no coincide con el de la consulta, el planificador lo ignora.
 *
 * Sólo PostgreSQL; en SQLite (tests) la tabla es pequeña y no hace falta.
 */
return new class extends Migration
{
    // CREATE INDEX CONCURRENTLY no puede ir dentro de una transacción.
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE INDEX CONCURRENTLY IF NOT EXISTS contratos_fecha_efectiva_index
                 ON contratos ((COALESCE(fecha_publicacion, fecha_adjudicacion, fecha_formalizacion)) DESC NULLS LAST)'
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS contratos_fecha_efectiva_index');
        }
    }
};
