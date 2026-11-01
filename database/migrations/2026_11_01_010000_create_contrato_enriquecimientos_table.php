<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registro de qué contratos se han consultado ya contra su XML de detalle.
 *
 * Sin él, regional:enrich-eusk volvía a pedir cada semana los ~85.000 XML del año: los
 * contratos menores de Euskadi publican el CPV vacío, así que nunca dejaban de parecer
 * pendientes. Ahora solo se repite la consulta si el contrato ha cambiado después (la
 * sincronización lo actualizó) o si la anterior falló, porque los fallos no se anotan.
 *
 * Tabla aparte, y no una columna en contratos, para no tocar el esquema de la tabla grande.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contrato_enriquecimientos', function (Blueprint $table) {
            $table->id();
            $table->string('placsp_id')->unique();
            $table->string('fuente', 20);
            $table->timestamp('consultado_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contrato_enriquecimientos');
    }
};
