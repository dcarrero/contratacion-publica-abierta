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
            $table->string('cpv', 255)->nullable()->change();
            $table->string('nuts', 50)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('contratos', function (Blueprint $table) {
            $table->string('cpv', 20)->nullable()->change();
            $table->string('nuts', 10)->nullable()->change();
        });
    }
};
