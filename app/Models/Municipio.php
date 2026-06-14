<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Municipio extends Model
{
    protected $fillable = [
        'codigo_ine',
        'nombre',
        'provincia_id',
        'poblacion',
        'lat',
        'lng',
    ];

    protected function casts(): array
    {
        return [
            'poblacion' => 'integer',
            'lat' => 'decimal:7',
            'lng' => 'decimal:7',
        ];
    }

    public function provincia(): BelongsTo
    {
        return $this->belongsTo(Provincia::class);
    }

    public function organismos(): HasMany
    {
        return $this->hasMany(Organismo::class);
    }
}
