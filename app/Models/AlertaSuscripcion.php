<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AlertaSuscripcion extends Model
{
    protected $table = 'alertas_suscripciones';

    protected $fillable = [
        'email',
        'tipo',
        'filtro_valor',
        'filtro_nombre',
        'frecuencia',
        'token',
        'verificada_at',
        'activa',
        'ultimo_envio_at',
    ];

    protected function casts(): array
    {
        return [
            'verificada_at' => 'datetime',
            'activa' => 'boolean',
            'ultimo_envio_at' => 'datetime',
        ];
    }

    public function scopeActiva(Builder $query): Builder
    {
        return $query->where('activa', true);
    }

    public function scopeVerificada(Builder $query): Builder
    {
        return $query->whereNotNull('verificada_at');
    }

    public function scopeTipo(Builder $query, string $tipo): Builder
    {
        return $query->where('tipo', $tipo);
    }

    public function scopeFrecuencia(Builder $query, string $frecuencia): Builder
    {
        return $query->where('frecuencia', $frecuencia);
    }

    public static function generarToken(): string
    {
        return Str::random(64);
    }
}
