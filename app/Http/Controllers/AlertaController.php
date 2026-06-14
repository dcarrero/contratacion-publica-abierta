<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Mail\VerificacionSuscripcion;
use App\Models\AlertaSuscripcion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;

class AlertaController extends Controller
{
    public function suscribir(Request $request): View
    {
        $validated = $request->validate([
            'email' => 'required|email|max:255',
            'tipo' => 'required|in:organismo,adjudicatario,busqueda',
            'filtro_valor' => 'required|string|max:255',
            'filtro_nombre' => 'required|string|max:500',
            'frecuencia' => 'sometimes|in:diaria,semanal',
        ]);

        $maxPorEmail = config('contratacion.alertas.max_por_email', 20);
        $existentes = AlertaSuscripcion::where('email', $validated['email'])->activa()->count();

        if ($existentes >= $maxPorEmail) {
            return view('alertas.mensaje', [
                'titulo' => 'Límite alcanzado',
                'mensaje' => "Ya tienes {$maxPorEmail} suscripciones activas. Cancela alguna antes de crear nuevas.",
                'tipo' => 'error',
            ]);
        }

        // Evitar duplicados exactos
        $existeDuplicada = AlertaSuscripcion::where('email', $validated['email'])
            ->where('tipo', $validated['tipo'])
            ->where('filtro_valor', $validated['filtro_valor'])
            ->activa()
            ->exists();

        if ($existeDuplicada) {
            return view('alertas.mensaje', [
                'titulo' => 'Ya estás suscrito',
                'mensaje' => 'Ya tienes una suscripción activa para este seguimiento.',
                'tipo' => 'info',
            ]);
        }

        $suscripcion = AlertaSuscripcion::create([
            'email' => $validated['email'],
            'tipo' => $validated['tipo'],
            'filtro_valor' => $validated['filtro_valor'],
            'filtro_nombre' => $validated['filtro_nombre'],
            'frecuencia' => $validated['frecuencia'] ?? 'diaria',
            'token' => AlertaSuscripcion::generarToken(),
        ]);

        Mail::to($suscripcion->email)->send(new VerificacionSuscripcion($suscripcion));

        return view('alertas.mensaje', [
            'titulo' => 'Revisa tu email',
            'mensaje' => "Hemos enviado un enlace de confirmación a {$suscripcion->email}. Haz clic en él para activar tu suscripción.",
            'tipo' => 'success',
        ]);
    }

    public function verificar(string $token): View
    {
        $expiryHours = config('contratacion.alertas.token_expiry_hours', 48);
        $suscripcion = AlertaSuscripcion::where('token', $token)
            ->where('created_at', '>', now()->subHours($expiryHours))
            ->first();

        if (! $suscripcion) {
            return view('alertas.mensaje', [
                'titulo' => 'Enlace no válido',
                'mensaje' => 'Este enlace de verificación no es válido o ha expirado.',
                'tipo' => 'error',
            ]);
        }

        if ($suscripcion->verificada_at) {
            return view('alertas.mensaje', [
                'titulo' => 'Ya verificada',
                'mensaje' => 'Esta suscripción ya estaba verificada.',
                'tipo' => 'info',
            ]);
        }

        $suscripcion->update(['verificada_at' => now()]);

        return view('alertas.mensaje', [
            'titulo' => 'Suscripción activada',
            'mensaje' => "Recibirás alertas sobre {$suscripcion->filtro_nombre} en {$suscripcion->email}.",
            'tipo' => 'success',
        ]);
    }

    public function cancelar(string $token): View
    {
        $suscripcion = AlertaSuscripcion::where('token', $token)->first();

        if (! $suscripcion) {
            return view('alertas.mensaje', [
                'titulo' => 'Enlace no válido',
                'mensaje' => 'Este enlace de cancelación no es válido.',
                'tipo' => 'error',
            ]);
        }

        $suscripcion->update(['activa' => false]);

        return view('alertas.mensaje', [
            'titulo' => 'Suscripción cancelada',
            'mensaje' => "Has cancelado las alertas sobre {$suscripcion->filtro_nombre}. Ya no recibirás más notificaciones.",
            'tipo' => 'success',
        ]);
    }
}
