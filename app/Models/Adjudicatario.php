<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\SqlDialect;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Adjudicatario extends Model
{
    protected $fillable = [
        'nif',
        'tipo_identificador',
        'pais',
        'nombre',
        'nombre_normalizado',
        'es_pyme',
        'total_contratos',
        'total_importe',
    ];

    protected function casts(): array
    {
        return [
            'es_pyme' => 'boolean',
            'total_contratos' => 'integer',
            'total_importe' => 'decimal:2',
        ];
    }

    public function contratos(): HasMany
    {
        return $this->hasMany(Contrato::class);
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(AdjudicatarioAlias::class);
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
                ->orWhere('nif', $like, "%{$term}%")
                ->orWhereHas('aliases', function (Builder $aliasQuery) use ($term, $like) {
                    $aliasQuery->where('nombre_variante', $like, "%{$term}%");
                });
        });
    }

    public function scopePyme(Builder $query): Builder
    {
        return $query->where('es_pyme', true);
    }
}
