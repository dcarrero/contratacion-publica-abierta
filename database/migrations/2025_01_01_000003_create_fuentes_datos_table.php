<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fuentes_datos', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 200);
            $table->string('slug', 100)->unique();
            $table->string('url', 500)->nullable();
            $table->string('tipo', 50); // atom, csv, api, manual
            $table->string('frecuencia', 50)->nullable(); // diaria, semanal, mensual, unica
            $table->timestamp('ultima_sincronizacion')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fuentes_datos');
    }
};
