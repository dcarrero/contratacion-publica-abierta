<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alertas_suscripciones', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->string('tipo');          // organismo, adjudicatario, busqueda
            $table->string('filtro_valor');   // NIF o query de búsqueda
            $table->string('filtro_nombre');  // nombre legible para el email
            $table->string('frecuencia')->default('diaria'); // diaria, semanal
            $table->string('token', 64)->unique();
            $table->timestamp('verificada_at')->nullable();
            $table->boolean('activa')->default(true);
            $table->timestamp('ultimo_envio_at')->nullable();
            $table->timestamps();

            $table->index('email');
            $table->index(['tipo', 'filtro_valor']);
            $table->index(['activa', 'verificada_at', 'frecuencia']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alertas_suscripciones');
    }
};
