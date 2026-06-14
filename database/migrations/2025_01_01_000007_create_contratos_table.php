<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contratos', function (Blueprint $table) {
            $table->id();

            // Identificación
            $table->string('placsp_id', 100)->unique();
            $table->string('expediente', 200)->nullable();
            $table->string('url_placsp', 500)->nullable();
            $table->string('url_xml', 500)->nullable();

            // Relaciones
            $table->foreignId('organismo_id')->constrained('organismos');
            $table->foreignId('adjudicatario_id')->nullable()->constrained('adjudicatarios')->nullOnDelete();
            $table->string('nif_organo', 20)->nullable();
            $table->string('nif_adjudicatario', 20)->nullable();
            $table->string('nombre_adjudicatario', 500)->nullable();

            // Datos contractuales
            $table->text('objeto')->nullable();
            $table->string('tipo_contrato', 50)->nullable();
            $table->string('procedimiento', 100)->nullable();
            $table->string('estado', 50)->nullable();
            $table->decimal('importe_licitacion', 15, 2)->nullable();
            $table->decimal('importe_licitacion_con_iva', 15, 2)->nullable();
            $table->decimal('importe_adjudicacion', 15, 2)->nullable();
            $table->decimal('importe_adjudicacion_con_iva', 15, 2)->nullable();
            $table->string('duracion', 100)->nullable();
            $table->string('cpv', 20)->nullable();
            $table->string('nuts', 10)->nullable();
            $table->string('lugar_ejecucion', 500)->nullable();
            $table->unsignedSmallInteger('num_ofertas')->nullable();
            $table->boolean('es_menor')->default(false);
            $table->boolean('es_clm')->default(true);

            // Fechas
            $table->date('fecha_publicacion')->nullable();
            $table->date('fecha_limite')->nullable();
            $table->date('fecha_adjudicacion')->nullable();
            $table->date('fecha_formalizacion')->nullable();
            $table->timestamp('fecha_updated')->nullable();

            // Meta
            $table->foreignId('fuente_datos_id')->nullable()->constrained('fuentes_datos')->nullOnDelete();
            $table->string('tipo_registro', 30)->default('licitacion'); // licitacion, menor
            $table->string('hash_contenido', 64)->nullable();
            $table->unsignedSmallInteger('version')->default(1);

            $table->timestamps();

            // Índices
            $table->index(['expediente', 'nif_organo']);
            $table->index('organismo_id');
            $table->index('adjudicatario_id');
            $table->index('nif_organo');
            $table->index('nif_adjudicatario');
            $table->index('fecha_publicacion');
            $table->index('fecha_adjudicacion');
            $table->index('tipo_contrato');
            $table->index('cpv');
            $table->index('estado');
            $table->index('importe_adjudicacion');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contratos');
    }
};
