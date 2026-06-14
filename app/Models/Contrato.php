<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\SqlDialect;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contrato extends Model
{
    protected $fillable = [
        'placsp_id',
        'expediente',
        'numero_lote',
        'url_placsp',
        'url_xml',
        'uuid_ted',
        'id_plataforma',
        'organismo_id',
        'adjudicatario_id',
        'nif_organo',
        'nif_adjudicatario',
        'pais_adjudicatario',
        'nombre_adjudicatario',
        'objeto',
        'tipo_contrato',
        'subtipo_contrato',
        'procedimiento',
        'urgencia',
        'estado',
        'resultado_codigo',
        'importe_licitacion',
        'importe_licitacion_con_iva',
        'importe_estimado',
        'importe_adjudicacion',
        'importe_adjudicacion_con_iva',
        'moneda',
        'duracion',
        'cpv',
        'nuts',
        'lugar_ejecucion',
        'ciudad_ejecucion',
        'codigo_postal_ejecucion',
        'num_ofertas',
        'criterios_adjudicacion',
        'es_menor',
        'es_clm',
        'financiacion_ue',
        'fecha_publicacion',
        'fecha_limite',
        'hora_limite',
        'fecha_adjudicacion',
        'fecha_formalizacion',
        'fecha_updated',
        'fuente_datos_id',
        'tipo_registro',
        'hash_contenido',
        'version',
    ];

    protected function casts(): array
    {
        return [
            'importe_licitacion' => 'decimal:2',
            'importe_licitacion_con_iva' => 'decimal:2',
            'importe_estimado' => 'decimal:2',
            'importe_adjudicacion' => 'decimal:2',
            'importe_adjudicacion_con_iva' => 'decimal:2',
            'num_ofertas' => 'integer',
            'criterios_adjudicacion' => 'array',
            'es_menor' => 'boolean',
            'es_clm' => 'boolean',
            'fecha_publicacion' => 'date',
            'fecha_limite' => 'date',
            'fecha_adjudicacion' => 'date',
            'fecha_formalizacion' => 'date',
            'fecha_updated' => 'datetime',
            'version' => 'integer',
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

    public function fuenteDatos(): BelongsTo
    {
        return $this->belongsTo(FuenteDatos::class, 'fuente_datos_id');
    }

    public function historial(): HasMany
    {
        return $this->hasMany(ContratoHistorial::class);
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        $term = trim($term);
        if (mb_strlen($term) < 2) {
            return $query;
        }

        // PostgreSQL: full-text search con stemming español (1000x más rápido)
        if (SqlDialect::isPgsql()) {
            return $query->where(function (Builder $q) use ($term) {
                $q->whereRaw("search_vector @@ plainto_tsquery('spanish', f_unaccent(?))", [$term])
                    ->orWhere('expediente', 'ILIKE', "%{$term}%")
                    ->orWhere('placsp_id', 'ILIKE', "%{$term}%");
            });
        }

        // SQLite/MariaDB: fallback a LIKE
        $like = SqlDialect::ilike();

        return $query->where(function (Builder $q) use ($term, $like) {
            $q->where('objeto', $like, "%{$term}%")
                ->orWhere('expediente', $like, "%{$term}%")
                ->orWhere('placsp_id', $like, "%{$term}%");
        });
    }

    public function scopeYear(Builder $query, int $year): Builder
    {
        return $query->whereYear('fecha_publicacion', $year);
    }

    public function scopeTipo(Builder $query, string $tipo): Builder
    {
        return $query->where('tipo_contrato', $tipo);
    }

    public function scopeMenores(Builder $query): Builder
    {
        return $query->where('es_menor', true);
    }

    public function scopeEstado(Builder $query, string $estado): Builder
    {
        $variants = config("contratacion.estados.{$estado}", [$estado]);

        $like = SqlDialect::ilike();

        return $query->where(function (Builder $q) use ($variants, $like) {
            $q->whereIn('estado', $variants);
            foreach ($variants as $v) {
                $q->orWhere('estado', $like, $v.'%');
            }
        });
    }

    public function scopeProcedimiento(Builder $query, string $proc): Builder
    {
        return $query->where('procedimiento', $proc);
    }

    public function scopeForOrganismo(Builder $query, int $organismoId): Builder
    {
        return $query->where('organismo_id', $organismoId);
    }

    public function scopeForAdjudicatario(Builder $query, int $adjudicatarioId): Builder
    {
        return $query->where('adjudicatario_id', $adjudicatarioId);
    }

    public function scopeCcaa(Builder $query, string $nuts2): Builder
    {
        return $query->where('nuts', 'LIKE', "{$nuts2}%");
    }
}
