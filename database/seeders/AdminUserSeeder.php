<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Crea o RECUPERA el administrador. Si la dirección ya existe (por ejemplo, porque
 * alguien la registró antes por el registro abierto), no se salta la fila: le impone la
 * contraseña de ADMIN_PASSWORD, la marca como admin, rota el remember_token y borra
 * sus sesiones abiertas, de modo que quien la tuviera pierde el acceso.
 */
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

        $email = Str::lower(trim((string) config('contratacion.admin.email')));

        $user = User::whereRaw('LOWER(email) = ?', [$email])->first() ?? new User(['email' => $email]);

        $user->forceFill([
            'name' => $user->name ?: 'Admin',
            'password' => Hash::make($password),
            'is_admin' => true,
            'remember_token' => Str::random(60),
        ])->save();

        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
        }
    }
}
