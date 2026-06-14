<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fuente_datos_id')->nullable()->constrained('fuentes_datos')->nullOnDelete();
            $table->string('tipo', 50); // bootstrap, sync_licitaciones, sync_menores
            $table->unsignedInteger('procesados')->default(0);
            $table->unsignedInteger('nuevos')->default(0);
            $table->unsignedInteger('actualizados')->default(0);
            $table->unsignedInteger('ignorados')->default(0);
            $table->unsignedInteger('errores')->default(0);
            $table->unsignedInteger('duracion_segundos')->nullable();
            $table->text('notas')->nullable();
            $table->timestamps();

            $table->index('fuente_datos_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_logs');
    }
};
