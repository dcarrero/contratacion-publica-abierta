<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContratoHistorial extends Model
{
    protected $table = 'contratos_historial';

    protected $fillable = [
        'contrato_id',
        'placsp_id',
        'datos_json',
        'fecha_updated',
    ];

    protected function casts(): array
    {
        return [
            'datos_json' => 'array',
            'fecha_updated' => 'datetime',
        ];
    }

    public function contrato(): BelongsTo
    {
        return $this->belongsTo(Contrato::class);
    }
}
