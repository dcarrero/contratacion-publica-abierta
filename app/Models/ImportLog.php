<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportLog extends Model
{
    protected $fillable = [
        'fuente_datos_id',
        'tipo',
        'procesados',
        'nuevos',
        'actualizados',
        'ignorados',
        'errores',
        'duracion_segundos',
        'notas',
    ];

    protected function casts(): array
    {
        return [
            'procesados' => 'integer',
            'nuevos' => 'integer',
            'actualizados' => 'integer',
            'ignorados' => 'integer',
            'errores' => 'integer',
            'duracion_segundos' => 'integer',
        ];
    }

    public function fuenteDatos(): BelongsTo
    {
        return $this->belongsTo(FuenteDatos::class, 'fuente_datos_id');
    }
}
