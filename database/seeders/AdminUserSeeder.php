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
        User::firstOrCreate(
            ['email' => config('contratacion.admin.email')],
            [
                'name' => 'Admin',
                'password' => Hash::make(config('contratacion.admin.default_password')),
            ]
        );
    }
}
