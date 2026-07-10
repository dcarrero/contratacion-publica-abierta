<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $password = config('contratacion.admin.default_password');

        if (blank($password)) {
            throw new \RuntimeException(
                'ADMIN_PASSWORD no está definido; abortando el seed del usuario admin. '
                .'Define una contraseña fuerte en el entorno antes de sembrar.'
            );
        }

        User::firstOrCreate(
            ['email' => config('contratacion.admin.email')],
            [
                'name' => 'Admin',
                'password' => Hash::make($password),
            ]
        );
    }
}
