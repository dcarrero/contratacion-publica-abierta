<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContratoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'placsp_id' => $this->placsp_id,
            'objeto' => $this->objeto,
            'tipo_contrato' => $this->tipo_contrato,
            'procedimiento' => $this->procedimiento,
            'estado' => $this->estado,
            'importe_adjudicacion' => $this->importe_adjudicacion,
            'importe_adjudicacion_con_iva' => $this->importe_adjudicacion_con_iva,
            'fecha_publicacion' => $this->fecha_publicacion?->format('Y-m-d'),
            'fecha_adjudicacion' => $this->fecha_adjudicacion?->format('Y-m-d'),
            'fecha_formalizacion' => $this->fecha_formalizacion?->format('Y-m-d'),
            'nuts' => $this->nuts,
            'cpv' => $this->cpv,
            'es_menor' => $this->es_menor,
            'num_ofertas' => $this->num_ofertas,
            'url_placsp' => $this->url_placsp,
            'organismo' => $this->whenLoaded('organismo', fn () => [
                'nif' => $this->organismo?->nif,
                'nombre' => $this->organismo?->nombre,
            ]),
            'adjudicatario' => $this->whenLoaded('adjudicatario', fn () => [
                'nif' => $this->adjudicatario?->nif,
                'nombre' => $this->adjudicatario?->nombre,
            ]),
        ];
    }
}
