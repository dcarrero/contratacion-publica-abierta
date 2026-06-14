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
            $table->string('nuts', 5)->nullable()->index()->after('nombre');
        });
    }

    public function down(): void
    {
        Schema::table('provincias', function (Blueprint $table) {
            $table->dropIndex(['nuts']);
            $table->dropColumn('nuts');
        });
    }
};
