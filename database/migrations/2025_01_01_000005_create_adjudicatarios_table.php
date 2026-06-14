<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('adjudicatarios', function (Blueprint $table) {
            $table->id();
            $table->string('nif', 20)->unique();
            $table->string('nombre', 500);
            $table->string('nombre_normalizado', 500)->nullable();
            $table->boolean('es_pyme')->nullable();
            $table->unsignedInteger('total_contratos')->default(0);
            $table->decimal('total_importe', 15, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('adjudicatarios');
    }
};
