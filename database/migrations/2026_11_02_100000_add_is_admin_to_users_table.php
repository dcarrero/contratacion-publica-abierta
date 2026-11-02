<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rol de administrador explícito. Hasta ahora el panel /admin solo exigía estar
 * autenticado, y el registro abierto daba una cuenta válida para él (auditoría 2026-11, AUTH-01).
 *
 * Nadie sale como admin de esta migración, a propósito: no se marca por email porque
 * la dirección del admin es pública y podría haberla registrado un tercero. El admin
 * se (re)crea con `php artisan db:seed --class=AdminUserSeeder`, que además fija la
 * contraseña de ADMIN_PASSWORD e invalida sus sesiones recordadas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_admin');
        });
    }
};
