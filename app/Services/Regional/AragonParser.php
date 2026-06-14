<?php

declare(strict_types=1);

namespace App\Services\Regional;

class AragonParser
{
    private const TIPO_CONTRATO_MAP = [
        'Servicios' => 'Servicios',
        'Suministros' => 'Suministros',
        'Suministro' => 'Suministros',
        'Obras' => 'Obras',
        'Obra' => 'Obras',
        'Gestión de Servicios Públicos' => 'Gestión de Servicios Públicos',
        'Concesión de Obras' => 'Concesión de Obras Públicas',
        'Concesión de Servicios' => 'Gestión de Servicios Públicos',
        'Patrimonial' => 'Patrimonial',
        'Administrativo especial' => 'Administrativo especial',
        'Administrativo Especial' => 'Administrativo especial',
        'Privado' => 'Privado',
        'Mixto' => 'Mixto',
    ];

    /**
     * Parsea un registro CSV de contratos de Aragón.
     *
     * @param  array<string, string>  $record
     * @param  int  $year  Año (por defecto se extrae del campo Ejercicio)
     * @return array<string, mixed>|null
     */
    public function parse(array $record, int $year = 0): ?array
    {
        $expediente = $record['Código Expediente'] ?? $record['Codigo Expediente']
            ?? $record['codigo expediente'] ?? $record['CODIGO EXPEDIENTE'] ?? null;

        if (empty($expediente)) {
            return null;
        }

        $ejercicio = $record['Ejercicio'] ?? $record['ejercicio'] ?? $record['EJERCICIO'] ?? null;
        $yearStr = ! empty($ejercicio) ? $ejercicio : ($year > 0 ? (string) $year : date('Y'));

        $placspId = "ARA-{$yearStr}-{$expediente}";

        // Organismo: sin NIF, generar sintético
        $organoNombre = $record['Organo'] ?? $record['organo'] ?? $record['ORGANO'] ?? null;
        $nifOrgano = null;

        if (! empty($organoNombre)) {
            $nifOrgano = 'ARA-'.mb_strtoupper(mb_substr(md5($organoNombre), 0, 8));
        }

        if (empty($nifOrgano)) {
            return null;
        }

        // Adjudicatario: NO tiene NIF, solo nombre
        // Generar NIF sintético basado en hash del nombre para agrupar
        $nombreAdj = $record['Identidad del adjudicatario'] ?? $record['identidad del adjudicatario']
            ?? $record['IDENTIDAD DEL ADJUDICATARIO']
            ?? $record['Identidad del Adjudicatario'] ?? null;
        $nifAdj = null;
        if (! empty($nombreAdj)) {
            $nifAdj = 'ARA-ADJ-'.mb_strtoupper(mb_substr(md5(mb_strtoupper(trim($nombreAdj))), 0, 8));
        }

        // Detectar si es contrato menor
        $esMenor = false;
        $mField = $record['M'] ?? $record['m'] ?? null;
        if (! empty($mField) && mb_stripos($mField, 'menor') !== false) {
            $esMenor = true;
        }

        $data = [
            'placsp_id' => $placspId,
            'expediente' => $expediente,
            'objeto' => $this->cleanEmpty($record['Obj'] ?? $record['obj'] ?? $record['OBJ'] ?? null),
            'nif_organo' => $nifOrgano,
            'nombre_organo' => $organoNombre,
            'tipo_contrato' => $this->mapTipoContrato(
                $record['Tipo de contrato'] ?? $record['tipo de contrato'] ?? $record['TIPO DE CONTRATO'] ?? null
            ),
            'procedimiento' => $this->cleanEmpty(
                $record['Procedimiento de adjudicación'] ?? $record['Procedimiento de adjudicacion']
                ?? $record['procedimiento de adjudicacion']
                ?? $record['PROCEDIMIENTO DE ADJUDICACION'] ?? null
            ),
            'importe_licitacion' => $this->parseImporte(
                $record['Importe de licitación'] ?? $record['Importe de licitacion']
                ?? $record['importe de licitacion']
                ?? $record['IMPORTE DE LICITACION'] ?? null
            ),
            'importe_adjudicacion' => $this->parseImporte(
                $record['Importe de adjudicación'] ?? $record['Importe de adjudicacion']
                ?? $record['importe de adjudicacion']
                ?? $record['IMPORTE DE ADJUDICACION'] ?? null
            ),
            'moneda' => 'EUR',
            'nif_adjudicatario' => $nifAdj,
            'nombre_adjudicatario' => $nombreAdj,
            'nuts' => config('contratacion.regional.aragon.default_nuts'),
            'num_ofertas' => $this->parseInteger(
                $record['Número de licitadores'] ?? $record['Numero de licitadores']
                ?? $record['numero de licitadores']
                ?? $record['NUMERO DE LICITADORES'] ?? null
            ),
            'es_menor' => $esMenor,
        ];

        return $data;
    }

    private function parseImporte(?string $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = trim($value);

        // Formato europeo: "100.000,00" → 100000.00
        if (preg_match('/^\d{1,3}(\.\d{3})*(,\d+)?$/', $value)) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        } else {
            $value = str_replace(',', '.', $value);
        }

        $float = (float) $value;

        return $float > 0 ? $float : null;
    }

    private function parseInteger(?string $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value ?: null;
    }

    private function cleanEmpty(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    private function mapTipoContrato(?string $tipo): ?string
    {
        if ($tipo === null || $tipo === '') {
            return null;
        }

        return self::TIPO_CONTRATO_MAP[$tipo] ?? $tipo;
    }
}
