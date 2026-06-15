<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Versión PÚBLICA de User (portal gratis, sin pagos): sin trait Billable de Cashier,
 * sin columna `plan` ni `isPro()`. sync-public.sh la copia sobre app/Models/User.php
 * cuando genera el snapshot abierto (igual que README/CHANGELOG).
 */
class User extends Authenticatable
{
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }
}
