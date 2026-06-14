<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Índice de patrón para acelerar `nuts LIKE 'PREFIJO%'` (radiografía por provincia, scopeCcaa, informes).
 *
 * El btree por defecto sobre varchar NO sirve para LIKE de prefijo con la colación por defecto: PG
 * escanea toda la tabla y filtra (~4-5s sobre 8,2M filas). Con `varchar_pattern_ops` el LIKE de prefijo
 * usa un rango de índice (milisegundos). Solo PostgreSQL; en SQLite (tests) es innecesario.
 */
return new class extends Migration
{
    // CREATE INDEX CONCURRENTLY no puede ir dentro de una transacción.
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS contratos_nuts_pattern_index ON contratos (nuts varchar_pattern_ops)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS contratos_nuts_pattern_index');
        }
    }
};
