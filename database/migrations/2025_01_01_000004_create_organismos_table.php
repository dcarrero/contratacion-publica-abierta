<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organismos', function (Blueprint $table) {
            $table->id();
            $table->string('nif', 20)->unique();
            $table->string('dir3', 20)->nullable();
            $table->string('nombre', 500);
            $table->string('nombre_normalizado', 500)->nullable();
            $table->string('tipo', 50)->nullable(); // autonomico, diputacion, ayuntamiento, sector_publico
            $table->foreignId('municipio_id')->nullable()->constrained('municipios')->nullOnDelete();
            $table->string('url_perfil_placsp', 500)->nullable();
            $table->boolean('activo')->default(true);
            $table->unsignedInteger('total_contratos')->default(0);
            $table->decimal('total_importe', 15, 2)->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organismos');
    }
};
