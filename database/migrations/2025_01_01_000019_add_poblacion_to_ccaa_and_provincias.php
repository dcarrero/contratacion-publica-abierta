<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comunidades_autonomas', function (Blueprint $table) {
            $table->unsignedInteger('poblacion')->nullable()->after('nuts');
        });

        Schema::table('provincias', function (Blueprint $table) {
            $table->unsignedInteger('poblacion')->nullable()->after('nuts');
        });
    }

    public function down(): void
    {
        Schema::table('comunidades_autonomas', function (Blueprint $table) {
            $table->dropColumn('poblacion');
        });

        Schema::table('provincias', function (Blueprint $table) {
            $table->dropColumn('poblacion');
        });
    }
};
