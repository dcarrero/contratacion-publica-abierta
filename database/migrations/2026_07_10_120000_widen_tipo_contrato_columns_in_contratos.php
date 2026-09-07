<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * C. Valenciana (y otras fuentes de datos abiertos) envían el tipo/subtipo de contrato
     * como texto descriptivo largo (p. ej. "Servicios de creación e interpretación artística
     * o literaria" = 60 chars), que no cabía en varchar(50) → SQLSTATE 22001 y filas perdidas
     * en el sync. Se ensanchan a 150. En PostgreSQL ampliar la longitud de un varchar es un
     * cambio de catálogo instantáneo (sin reescribir la tabla).
     */
    public function up(): void
    {
        Schema::table('contratos', function (Blueprint $table) {
            $table->string('tipo_contrato', 150)->nullable()->change();
            $table->string('subtipo_contrato', 150)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('contratos', function (Blueprint $table) {
            $table->string('tipo_contrato', 50)->nullable()->change();
            $table->string('subtipo_contrato', 50)->nullable()->change();
        });
    }
};
