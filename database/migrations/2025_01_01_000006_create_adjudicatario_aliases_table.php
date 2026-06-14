<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('adjudicatario_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('adjudicatario_id')->constrained('adjudicatarios')->cascadeOnDelete();
            $table->string('nombre_variante', 500);
            $table->unsignedInteger('veces_visto')->default(1);
            $table->timestamp('primera_vez')->nullable();
            $table->timestamp('ultima_vez')->nullable();

            $table->unique(['adjudicatario_id', 'nombre_variante'], 'adj_alias_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('adjudicatario_aliases');
    }
};
