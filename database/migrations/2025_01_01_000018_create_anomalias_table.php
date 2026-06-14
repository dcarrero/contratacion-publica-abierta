<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('anomalias', function (Blueprint $table) {
            $table->id();
            $table->string('tipo');          // fraccionamiento, concentracion, umbral, pico_temporal
            $table->string('severidad');     // alta, media, baja
            $table->text('descripcion');
            $table->foreignId('organismo_id')->nullable()->constrained('organismos')->nullOnDelete();
            $table->foreignId('adjudicatario_id')->nullable()->constrained('adjudicatarios')->nullOnDelete();
            $table->text('datos_json')->nullable(); // cast array — contratos involucrados, importes, etc.
            $table->string('periodo');       // e.g. "2025-Q4", "2026-01"
            $table->boolean('revisada')->default(false);
            $table->timestamps();

            $table->index('tipo');
            $table->index('severidad');
            $table->index(['tipo', 'organismo_id', 'periodo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('anomalias');
    }
};
