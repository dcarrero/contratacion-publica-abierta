<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comunidades_autonomas', function (Blueprint $table) {
            $table->id();
            $table->string('codigo_ine', 2)->unique();
            $table->string('nombre', 100);
            $table->string('nuts', 4)->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comunidades_autonomas');
    }
};
