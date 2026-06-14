<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contratos_historial', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contrato_id')->constrained('contratos')->cascadeOnDelete();
            $table->string('placsp_id', 100);
            $table->text('datos_json');
            $table->timestamp('fecha_updated')->nullable();
            $table->timestamps();

            $table->index('contrato_id');
            $table->index('placsp_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contratos_historial');
    }
};
