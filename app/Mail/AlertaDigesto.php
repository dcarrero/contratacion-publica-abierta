<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\AlertaSuscripcion;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

class AlertaDigesto extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public AlertaSuscripcion $suscripcion,
        public Collection $contratos,
    ) {}

    public function envelope(): Envelope
    {
        $count = $this->contratos->count();

        return new Envelope(
            subject: "{$count} nuevos contratos — {$this->suscripcion->filtro_nombre}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.alerta-digesto',
            with: [
                'contratos' => $this->contratos,
                'nombre' => $this->suscripcion->filtro_nombre,
                'tipo' => $this->suscripcion->tipo,
                'cancelarUrl' => url("/alertas/cancelar/{$this->suscripcion->token}"),
            ],
        );
    }
}
