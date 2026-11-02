<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AdminLoginController extends Controller
{
    /** Intentos fallidos por cuenta antes de bloquearla temporalmente. */
    private const MAX_ATTEMPTS_PER_ACCOUNT = 5;

    /** Duración del bloqueo por cuenta, en segundos. */
    private const LOCKOUT_SECONDS = 900;

    /** Hash bcrypt de una cadena aleatoria desechada; no corresponde a ninguna contraseña. */
    private const DUMMY_HASH = '$2y$12$jqu2U5ESJNyy9BQx3dmSo.zVysxDsYnzVPPY4KVvqjhp1aieZPVJC';

    public function showLogin(): View|RedirectResponse
    {
        if (Auth::user()?->isAdmin()) {
            return redirect()->route('admin.dashboard');
        }

        return view('admin.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        // Límite por cuenta además del de IP de la ruta: repartir los intentos entre
        // muchas IP no debe dar presupuesto infinito contra una misma cuenta.
        $accountKey = 'admin-login:'.Str::transliterate(Str::lower($credentials['email']));

        if (RateLimiter::tooManyAttempts($accountKey, self::MAX_ATTEMPTS_PER_ACCOUNT)) {
            Log::warning('Admin login bloqueado por exceso de intentos', [
                'email' => $credentials['email'],
                'ip' => $request->ip(),
            ]);

            throw ValidationException::withMessages([
                'email' => 'Demasiados intentos. Prueba de nuevo en unos minutos.',
            ]);
        }

        // Se valida a mano en vez de Auth::attempt() para no abrir sesión con una
        // cuenta que no es de administrador, ni siquiera un instante.
        $user = User::where('email', $credentials['email'])->first();

        // Se comprueba siempre un hash (el señuelo si la cuenta no existe) para que el
        // tiempo de respuesta no delate qué emails están registrados.
        $passwordOk = Hash::check($credentials['password'], $user->password ?? self::DUMMY_HASH);

        if (! $user || ! $passwordOk || ! $user->isAdmin()) {
            RateLimiter::hit($accountKey, self::LOCKOUT_SECONDS);

            Log::warning('Admin login fallido', [
                'email' => $credentials['email'],
                'ip' => $request->ip(),
            ]);

            return back()->withErrors([
                'email' => 'Credenciales incorrectas.',
            ])->onlyInput('email');
        }

        RateLimiter::clear($accountKey);

        // Sin «recordarme»: el panel exige iniciar sesión de nuevo al caducar la sesión.
        Auth::login($user);
        $request->session()->regenerate();

        Log::info('Admin login correcto', ['user_id' => $user->id, 'ip' => $request->ip()]);

        return redirect()->intended(route('admin.dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }
}
