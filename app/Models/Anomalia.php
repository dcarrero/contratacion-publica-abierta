<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Anomalia extends Model
{
    protected $table = 'anomalias';

    protected $fillable = [
        'tipo',
        'severidad',
        'descripcion',
        'organismo_id',
        'adjudicatario_id',
        'datos_json',
        'periodo',
        'revisada',
    ];

    protected function casts(): array
    {
        return [
            'datos_json' => 'array',
            'revisada' => 'boolean',
        ];
    }

    public function organismo(): BelongsTo
    {
        return $this->belongsTo(Organismo::class);
    }

    public function adjudicatario(): BelongsTo
    {
        return $this->belongsTo(Adjudicatario::class);
    }

    public function scopeTipo(Builder $query, string $tipo): Builder
    {
        return $query->where('tipo', $tipo);
    }

    public function scopeSeveridad(Builder $query, string $severidad): Builder
    {
        return $query->where('severidad', $severidad);
    }

    public function scopeNoRevisada(Builder $query): Builder
    {
        return $query->where('revisada', false);
    }

    public function scopePeriodo(Builder $query, string $periodo): Builder
    {
        return $query->where('periodo', $periodo);
    }
}
