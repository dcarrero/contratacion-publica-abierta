<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Provincia extends Model
{
    protected $fillable = [
        'codigo_ine',
        'nombre',
        'nuts',
        'comunidad_autonoma_id',
        'poblacion',
    ];

    public function comunidadAutonoma(): BelongsTo
    {
        return $this->belongsTo(ComunidadAutonoma::class);
    }

    public function municipios(): HasMany
    {
        return $this->hasMany(Municipio::class);
    }
}
