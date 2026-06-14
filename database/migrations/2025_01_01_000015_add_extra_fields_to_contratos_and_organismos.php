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
            // Importes adicionales
            $table->decimal('importe_estimado', 15, 2)->nullable()->after('importe_licitacion_con_iva');

            // Clasificación adicional
            $table->string('subtipo_contrato', 50)->nullable()->after('tipo_contrato');
            $table->string('urgencia', 20)->nullable()->after('procedimiento');

            // Financiación UE
            $table->string('financiacion_ue', 50)->nullable()->after('es_clm');

            // Lugar de ejecución ampliado
            $table->string('ciudad_ejecucion', 255)->nullable()->after('lugar_ejecucion');
            $table->string('codigo_postal_ejecucion', 10)->nullable()->after('ciudad_ejecucion');

            // Hora límite presentación
            $table->time('hora_limite')->nullable()->after('fecha_limite');

            // UUID TED (publicaciones transnacionales)
            $table->string('uuid_ted', 50)->nullable()->after('url_xml');

            // Criterios de adjudicación (JSON almacenado como text)
            $table->text('criterios_adjudicacion')->nullable()->after('num_ofertas');

            // Índices
            $table->index('urgencia');
            $table->index('subtipo_contrato');
        });

        Schema::table('organismos', function (Blueprint $table) {
            // Contacto
            $table->string('contacto_nombre', 255)->nullable()->after('url_perfil_placsp');
            $table->string('contacto_email', 255)->nullable()->after('contacto_nombre');
            $table->string('contacto_telefono', 30)->nullable()->after('contacto_email');

            // Dirección
            $table->string('direccion', 500)->nullable()->after('contacto_telefono');
            $table->string('ciudad', 255)->nullable()->after('direccion');
            $table->string('codigo_postal', 10)->nullable()->after('ciudad');

            // Clasificación
            $table->string('nivel_administracion', 50)->nullable()->after('tipo');
            $table->string('codigo_actividad', 10)->nullable()->after('nivel_administracion');
        });
    }

    public function down(): void
    {
        Schema::table('contratos', function (Blueprint $table) {
            $table->dropIndex(['urgencia']);
            $table->dropIndex(['subtipo_contrato']);
            $table->dropColumn([
                'importe_estimado',
                'subtipo_contrato',
                'urgencia',
                'financiacion_ue',
                'ciudad_ejecucion',
                'codigo_postal_ejecucion',
                'hora_limite',
                'uuid_ted',
                'criterios_adjudicacion',
            ]);
        });

        Schema::table('organismos', function (Blueprint $table) {
            $table->dropColumn([
                'contacto_nombre',
                'contacto_email',
                'contacto_telefono',
                'direccion',
                'ciudad',
                'codigo_postal',
                'nivel_administracion',
                'codigo_actividad',
            ]);
        });
    }
};
