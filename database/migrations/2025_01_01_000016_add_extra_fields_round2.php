<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contratos', function (Blueprint $table) {
            $table->string('moneda', 3)->default('EUR')->after('importe_adjudicacion_con_iva');
            $table->string('id_plataforma', 30)->nullable()->after('uuid_ted');
            $table->unsignedSmallInteger('numero_lote')->nullable()->after('expediente');
            $table->string('resultado_codigo', 20)->nullable()->after('estado');
            $table->string('pais_adjudicatario', 2)->nullable()->after('nif_adjudicatario');

            $table->index('resultado_codigo');
            $table->index('numero_lote');
        });

        Schema::table('adjudicatarios', function (Blueprint $table) {
            $table->string('pais', 2)->default('ES')->after('nif');
            $table->string('tipo_identificador', 10)->default('NIF')->after('nif');
        });

        Schema::table('organismos', function (Blueprint $table) {
            $table->string('nuts', 10)->nullable()->index()->after('dir3');
            $table->string('id_plataforma', 30)->nullable()->after('dir3');
        });
    }

    public function down(): void
    {
        Schema::table('contratos', function (Blueprint $table) {
            $table->dropIndex(['resultado_codigo']);
            $table->dropIndex(['numero_lote']);
            $table->dropColumn(['moneda', 'id_plataforma', 'numero_lote', 'resultado_codigo', 'pais_adjudicatario']);
        });

        Schema::table('adjudicatarios', function (Blueprint $table) {
            $table->dropColumn(['pais', 'tipo_identificador']);
        });

        Schema::table('organismos', function (Blueprint $table) {
            $table->dropIndex(['nuts']);
            $table->dropColumn(['nuts', 'id_plataforma']);
        });
    }
};
