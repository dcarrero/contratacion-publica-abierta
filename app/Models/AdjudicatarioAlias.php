<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdjudicatarioAlias extends Model
{
    public $timestamps = false;

    protected $table = 'adjudicatario_aliases';

    protected $fillable = [
        'adjudicatario_id',
        'nombre_variante',
        'veces_visto',
        'primera_vez',
        'ultima_vez',
    ];

    protected function casts(): array
    {
        return [
            'veces_visto' => 'integer',
            'primera_vez' => 'datetime',
            'ultima_vez' => 'datetime',
        ];
    }

    public function adjudicatario(): BelongsTo
    {
        return $this->belongsTo(Adjudicatario::class);
    }
}
