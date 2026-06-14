<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provincias', function (Blueprint $table) {
            $table->foreignId('comunidad_autonoma_id')
                ->nullable()
                ->after('nombre')
                ->constrained('comunidades_autonomas')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('provincias', function (Blueprint $table) {
            $table->dropConstrainedForeignId('comunidad_autonoma_id');
        });
    }
};
