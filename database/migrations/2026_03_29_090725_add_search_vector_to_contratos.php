<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS unaccent');

        // Wrapper IMMUTABLE para poder usar unaccent en columnas generadas
        DB::statement('
            CREATE OR REPLACE FUNCTION f_unaccent(text) RETURNS text AS $$
                SELECT public.unaccent($1);
            $$ LANGUAGE SQL IMMUTABLE PARALLEL SAFE
        ');

        DB::statement("
            ALTER TABLE contratos
            ADD COLUMN search_vector tsvector
            GENERATED ALWAYS AS (
                to_tsvector('spanish', f_unaccent(coalesce(objeto, '')))
            ) STORED
        ");

        DB::statement('CREATE INDEX idx_contratos_search_vector ON contratos USING GIN (search_vector)');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS idx_contratos_search_vector');
        DB::statement('ALTER TABLE contratos DROP COLUMN IF EXISTS search_vector');
        DB::statement('DROP FUNCTION IF EXISTS f_unaccent(text)');
    }
};
