<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ContratoResource;
use App\Models\ComunidadAutonoma;
use App\Models\Contrato;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class ContratoApiController extends Controller
{
    private const DEFAULT_PER_PAGE = 25;

    private const MAX_PER_PAGE = 100;

    /**
     * GET /api/v1/contratos
     *
     * Filtros soportados:
     *   ?ccaa=ES42       — código NUTS2 de comunidad autónoma
     *   ?year=2024       — año de publicación
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = $this->resolvePerPage($request);

        $query = Contrato::query()
            ->with(['organismo:id,nif,nombre', 'adjudicatario:id,nif,nombre'])
            ->select([
                'id', 'placsp_id', 'objeto', 'tipo_contrato', 'procedimiento',
                'estado', 'importe_adjudicacion', 'importe_adjudicacion_con_iva',
                'fecha_publicacion', 'fecha_adjudicacion', 'fecha_formalizacion',
                'nuts', 'cpv', 'es_menor', 'num_ofertas', 'url_placsp',
                'organismo_id', 'adjudicatario_id',
            ])
            ->orderByDesc('fecha_publicacion');

        if ($request->filled('ccaa')) {
            $nuts = $this->validCcaa($request->input('ccaa'));
            $query->ccaa($nuts);
        }

        if ($request->filled('year')) {
            $query->year((int) $request->input('year'));
        }

        return ContratoResource::collection($query->paginate($perPage)->withQueryString());
    }

    private function resolvePerPage(Request $request): int
    {
        if (! $request->filled('per_page')) {
            return self::DEFAULT_PER_PAGE;
        }

        $value = (int) $request->input('per_page');

        if ($value < 1 || $value > self::MAX_PER_PAGE) {
            throw ValidationException::withMessages([
                'per_page' => 'El parámetro per_page debe estar entre 1 y '.self::MAX_PER_PAGE.'.',
            ]);
        }

        return $value;
    }

    /**
     * Valida un código NUTS2 contra la BD para evitar LIKE injection.
     */
    private function validCcaa(string $ccaa): string
    {
        $nuts = ComunidadAutonoma::where('nuts', $ccaa)->value('nuts');

        if ($nuts === null) {
            throw ValidationException::withMessages([
                'ccaa' => "Código NUTS2 no válido: {$ccaa}. Ejemplo: ES42.",
            ]);
        }

        return $nuts;
    }
}
