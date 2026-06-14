<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Mail\AlertaDigesto;
use App\Models\AlertaSuscripcion;
use App\Models\Contrato;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class EnviarAlertas extends Command
{
    protected $signature = 'alertas:enviar
        {--frecuencia=diaria : Frecuencia de las alertas a enviar (diaria|semanal)}';

    protected $description = 'Envía digestos de alertas a los suscriptores';

    private int $enviados = 0;

    private int $sinNovedades = 0;

    private int $errores = 0;

    public function handle(): int
    {
        $frecuencia = $this->option('frecuencia');
        $this->info("Enviando alertas ({$frecuencia})...");

        $suscripciones = AlertaSuscripcion::activa()
            ->verificada()
            ->frecuencia($frecuencia)
            ->get();

        $this->info("  Suscripciones activas: {$suscripciones->count()}");

        foreach ($suscripciones as $suscripcion) {
            try {
                $this->procesarSuscripcion($suscripcion);
            } catch (\Throwable $e) {
                $this->error("  Error procesando suscripción #{$suscripcion->id}: {$e->getMessage()}");
                $this->errores++;
            }
        }

        $this->newLine();
        $this->table(['Métrica', 'Valor'], [
            ['Digestos enviados', $this->enviados],
            ['Sin novedades', $this->sinNovedades],
            ['Errores', $this->errores],
        ]);

        return self::SUCCESS;
    }

    private function procesarSuscripcion(AlertaSuscripcion $suscripcion): void
    {
        $desde = $suscripcion->ultimo_envio_at ?? $suscripcion->verificada_at;

        $contratos = $this->buscarContratos($suscripcion, $desde);

        if ($contratos->isEmpty()) {
            $this->sinNovedades++;

            return;
        }

        Mail::to($suscripcion->email)->send(new AlertaDigesto($suscripcion, $contratos));

        $suscripcion->update(['ultimo_envio_at' => now()]);

        $this->enviados++;
        $this->line("  Enviado a {$suscripcion->email}: {$contratos->count()} contratos nuevos ({$suscripcion->filtro_nombre})");
    }

    private function buscarContratos(AlertaSuscripcion $suscripcion, $desde)
    {
        $query = Contrato::with(['organismo', 'adjudicatario'])
            ->where('created_at', '>=', $desde)
            ->orderByDesc('fecha_publicacion')
            ->limit(50);

        switch ($suscripcion->tipo) {
            case 'organismo':
                $query->whereHas('organismo', fn ($q) => $q->where('nif', $suscripcion->filtro_valor));
                break;

            case 'adjudicatario':
                $query->whereHas('adjudicatario', fn ($q) => $q->where('nif', $suscripcion->filtro_valor));
                break;

            case 'busqueda':
                $query->search($suscripcion->filtro_valor);
                break;
        }

        return $query->get();
    }
}
