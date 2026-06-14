<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FuenteDatos extends Model
{
    protected $table = 'fuentes_datos';

    protected $fillable = [
        'nombre',
        'slug',
        'url',
        'tipo',
        'frecuencia',
        'ultima_sincronizacion',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'ultima_sincronizacion' => 'datetime',
            'activo' => 'boolean',
        ];
    }

    public function contratos(): HasMany
    {
        return $this->hasMany(Contrato::class, 'fuente_datos_id');
    }

    public function importLogs(): HasMany
    {
        return $this->hasMany(ImportLog::class, 'fuente_datos_id');
    }
}
