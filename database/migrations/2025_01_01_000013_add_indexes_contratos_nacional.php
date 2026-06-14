<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contratos', function (Blueprint $table) {
            $table->index('nuts');
        });

        // Change es_clm default from true to false
        Schema::table('contratos', function (Blueprint $table) {
            $table->boolean('es_clm')->default(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('contratos', function (Blueprint $table) {
            $table->dropIndex(['nuts']);
        });

        Schema::table('contratos', function (Blueprint $table) {
            $table->boolean('es_clm')->default(true)->change();
        });
    }
};
