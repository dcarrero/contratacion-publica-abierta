<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\SqlDialect;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Organismo extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'nif',
        'dir3',
        'id_plataforma',
        'nuts',
        'nombre',
        'nombre_normalizado',
        'tipo',
        'nivel_administracion',
        'codigo_actividad',
        'municipio_id',
        'url_perfil_placsp',
        'contacto_nombre',
        'contacto_email',
        'contacto_telefono',
        'direccion',
        'ciudad',
        'codigo_postal',
        'activo',
        'total_contratos',
        'total_importe',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
            'total_contratos' => 'integer',
            'total_importe' => 'decimal:2',
        ];
    }

    public function municipio(): BelongsTo
    {
        return $this->belongsTo(Municipio::class);
    }

    public function contratos(): HasMany
    {
        return $this->hasMany(Contrato::class);
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        $term = trim($term);
        if (mb_strlen($term) < 2) {
            return $query;
        }

        $like = SqlDialect::ilike();

        return $query->where(function (Builder $q) use ($term, $like) {
            $q->where('nombre', $like, "%{$term}%")
                ->orWhere('nif', $like, "%{$term}%");
        });
    }

    public function scopeTipo(Builder $query, string $tipo): Builder
    {
        return $query->where('tipo', $tipo);
    }

    public function scopeActivo(Builder $query): Builder
    {
        return $query->where('activo', true);
    }
}
