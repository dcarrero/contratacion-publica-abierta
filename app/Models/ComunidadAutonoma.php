<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ComunidadAutonoma extends Model
{
    protected $table = 'comunidades_autonomas';

    protected $fillable = [
        'codigo_ine',
        'nombre',
        'nuts',
        'poblacion',
    ];

    public function provincias(): HasMany
    {
        return $this->hasMany(Provincia::class);
    }
}
