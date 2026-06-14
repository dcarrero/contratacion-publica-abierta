<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Adjudicatario;
use App\Models\ComunidadAutonoma;
use App\Models\Contrato;
use App\Models\Organismo;
use Illuminate\Http\Request;
use League\Csv\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController extends Controller
{
    /**
     * Sanitiza valor para CSV: previene inyección de fórmulas en Excel.
     * Si empieza con =, +, -, @ o \t, se prefija con apóstrofe.
     */
    private function csvSafe(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        if (in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$value;
        }

        return $value;
    }

    /**
     * Valida código NUTS de CCAA contra la BD. Previene LIKE injection.
     */
    private function validCcaa(?string $ccaa): ?string
    {
        if ($ccaa === null || $ccaa === '') {
            return null;
        }

        return ComunidadAutonoma::where('nuts', $ccaa)->value('nuts');
    }

    public function contratos(Request $request): StreamedResponse
    {
        $maxRows = config('contratacion.informes.max_csv_rows', 500000);

        return new StreamedResponse(function () use ($request, $maxRows) {
            $csv = Writer::createFromStream(fopen('php://output', 'w'));

            // BOM UTF-8 para Excel
            $csv->setOutputBOM(Writer::BOM_UTF8);

            $csv->insertOne([
                'placsp_id', 'expediente', 'objeto', 'tipo_contrato', 'procedimiento',
                'estado', 'importe_adjudicacion', 'importe_adjudicacion_con_iva',
                'fecha_publicacion', 'fecha_adjudicacion', 'fecha_formalizacion',
                'nif_organo', 'organismo', 'nif_adjudicatario', 'adjudicatario',
                'nuts', 'cpv', 'es_menor', 'num_ofertas', 'url_placsp',
            ]);

            $query = Contrato::query()
                ->with(['organismo:id,nombre', 'adjudicatario:id,nombre']);

            // Filtros
            $ccaa = $this->validCcaa($request->input('ccaa'));
            if ($ccaa) {
                $query->where('nuts', 'LIKE', $ccaa.'%');
            }
            if ($request->filled('year')) {
                $query->year((int) $request->input('year'));
            }
            if ($request->filled('tipo_contrato')) {
                $query->tipo($request->input('tipo_contrato'));
            }
            if ($request->filled('procedimiento')) {
                $query->procedimiento($request->input('procedimiento'));
            }
            if ($request->filled('importe_min')) {
                $query->where('importe_adjudicacion', '>=', (float) $request->input('importe_min'));
            }
            if ($request->filled('importe_max')) {
                $query->where('importe_adjudicacion', '<=', (float) $request->input('importe_max'));
            }
            if ($request->filled('busqueda')) {
                $query->search($request->input('busqueda'));
            }

            $count = 0;
            foreach ($query->cursor() as $contrato) {
                if (++$count > $maxRows) {
                    break;
                }

                $csv->insertOne([
                    $contrato->placsp_id,
                    $this->csvSafe($contrato->expediente),
                    $this->csvSafe($contrato->objeto),
                    $contrato->tipo_contrato,
                    $contrato->procedimiento,
                    $contrato->estado,
                    $contrato->importe_adjudicacion,
                    $contrato->importe_adjudicacion_con_iva,
                    $contrato->fecha_publicacion?->format('Y-m-d'),
                    $contrato->fecha_adjudicacion?->format('Y-m-d'),
                    $contrato->fecha_formalizacion?->format('Y-m-d'),
                    $contrato->nif_organo,
                    $contrato->organismo?->nombre,
                    $contrato->nif_adjudicatario,
                    $contrato->adjudicatario?->nombre,
                    $contrato->nuts,
                    $contrato->cpv,
                    $contrato->es_menor ? '1' : '0',
                    $contrato->num_ofertas,
                    $contrato->url_placsp,
                ]);
            }
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="contratos.csv"',
            'Cache-Control' => 'no-cache',
        ]);
    }

    public function organismos(Request $request): StreamedResponse
    {
        return new StreamedResponse(function () use ($request) {
            $csv = Writer::createFromStream(fopen('php://output', 'w'));
            $csv->setOutputBOM(Writer::BOM_UTF8);

            $csv->insertOne([
                'nif', 'nombre', 'dir3', 'nivel_administracion',
                'total_contratos', 'total_importe', 'nuts',
            ]);

            $query = Organismo::query();
            $ccaa = $this->validCcaa($request->input('ccaa'));
            if ($ccaa) {
                $query->where('nuts', 'LIKE', $ccaa.'%');
            }

            foreach ($query->cursor() as $org) {
                $csv->insertOne([
                    $org->nif,
                    $org->nombre,
                    $org->dir3,
                    $org->nivel_administracion,
                    $org->total_contratos,
                    $org->total_importe,
                    $org->nuts,
                ]);
            }
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="organismos.csv"',
            'Cache-Control' => 'no-cache',
        ]);
    }

    public function adjudicatarios(Request $request): StreamedResponse
    {
        return new StreamedResponse(function () use ($request) {
            $csv = Writer::createFromStream(fopen('php://output', 'w'));
            $csv->setOutputBOM(Writer::BOM_UTF8);

            $csv->insertOne([
                'nif', 'nombre', 'total_contratos', 'total_importe',
            ]);

            $query = Adjudicatario::query();
            $ccaa = $this->validCcaa($request->input('ccaa'));
            if ($ccaa) {
                $query->whereExists(function ($q) use ($ccaa) {
                    $q->select(\Illuminate\Support\Facades\DB::raw(1))
                        ->from('contratos')
                        ->whereColumn('contratos.adjudicatario_id', 'adjudicatarios.id')
                        ->where('contratos.nuts', 'LIKE', $ccaa.'%');
                });
            }

            foreach ($query->cursor() as $adj) {
                $csv->insertOne([
                    $adj->nif,
                    $adj->nombre,
                    $adj->total_contratos,
                    $adj->total_importe,
                ]);
            }
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="adjudicatarios.csv"',
            'Cache-Control' => 'no-cache',
        ]);
    }
}
