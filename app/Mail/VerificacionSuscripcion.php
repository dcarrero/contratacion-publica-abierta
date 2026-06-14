<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\AlertaSuscripcion;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class VerificacionSuscripcion extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public AlertaSuscripcion $suscripcion,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Confirma tu suscripción — Contratación Abierta',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.verificacion-suscripcion',
            with: [
                'url' => url("/alertas/verificar/{$this->suscripcion->token}"),
                'nombre' => $this->suscripcion->filtro_nombre,
                'tipo' => $this->suscripcion->tipo,
            ],
        );
    }
}
