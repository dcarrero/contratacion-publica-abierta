<?php

declare(strict_types=1);

namespace App\Services\Regional;

class CastillaYLeonParser
{
    /**
     * Parsea un registro CSV de contratos de Castilla y León.
     *
     * Los CSVs de datosabiertos.jcyl.es usan nombres en minúsculas con guiones bajos.
     *
     * @param  array<string, string>  $record
     * @return array<string, mixed>|null
     */
    public function parse(array $record): ?array
    {
        $expediente = $record['codigo_expediente'] ?? $record['codigo_contrato']
            ?? $record['EXPEDIENTE'] ?? $record['expediente']
            ?? $record['NUM_EXPEDIENTE'] ?? $record['num_expediente'] ?? null;

        if (empty($expediente)) {
            return null;
        }

        // Generar placsp_id sintético
        $placspId = 'CYL-'.$expediente;

        // Organismo: el dataset de CyL no tiene NIF del órgano, solo nombre
        $organoNombre = $record['organo'] ?? $record['ORGANO'] ?? $record['ORGANO_CONTRATACION'] ?? null;
        $nifOrgano = $record['nif_organo'] ?? $record['NIF_ORGANO'] ?? $record['CIF_ORGANO'] ?? null;

        if (empty($nifOrgano) && ! empty($organoNombre)) {
            $nifOrgano = 'CYL-'.mb_strtoupper(mb_substr(md5($organoNombre), 0, 8));
        }

        if (empty($nifOrgano)) {
            return null;
        }

        // Importes: el dataset solo tiene importes con IVA, estimar sin IVA
        $importeConIva = $this->parseImporte(
            $record['presupuesto_de_adjudicacion_iva_incluido']
            ?? $record['IMPORTE_CON_IVA'] ?? $record['importe_con_iva'] ?? null
        );
        $importeSinIva = $importeConIva !== null ? round($importeConIva / 1.21, 2) : null;
        $importeLicitacionConIva = $this->parseImporte(
            $record['presupuesto_de_licitacion_iva_incluido']
            ?? $record['PRESUPUESTO_LICITACION'] ?? null
        );
        $importeLicitacionSinIva = $importeLicitacionConIva !== null ? round($importeLicitacionConIva / 1.21, 2) : null;

        // Duracion: combinar meses y días
        $duracion = $this->parseDuracion($record);

        $data = [
            'placsp_id' => $placspId,
            'expediente' => $expediente,
            'objeto' => $record['titulo'] ?? $record['TITULO'] ?? $record['OBJETO'] ?? $record['objeto'] ?? null,
            'nif_organo' => $nifOrgano,
            'nombre_organo' => $organoNombre,
            'tipo_contrato' => $record['tipo_de_contrato'] ?? $record['TIPO_CONTRATO'] ?? $record['tipo_contrato'] ?? null,
            'procedimiento' => $record['procedimiento_de_adjudicacion'] ?? $record['PROCEDIMIENTO'] ?? $record['procedimiento'] ?? null,
            'importe_licitacion' => $importeLicitacionSinIva,
            'importe_licitacion_con_iva' => $importeLicitacionConIva,
            'importe_adjudicacion' => $importeSinIva,
            'importe_adjudicacion_con_iva' => $importeConIva,
            'moneda' => 'EUR',
            'nif_adjudicatario' => $this->cleanNif(
                $record['nif_adjudicatario'] ?? $record['NIF_ADJUDICATARIO'] ?? null
            ),
            'nombre_adjudicatario' => $record['identidad_del_adjudicatario'] ?? $record['ADJUDICATARIO']
                ?? $record['adjudicatario'] ?? $record['NOMBRE_ADJUDICATARIO'] ?? null,
            'nuts' => config('contratacion.regional.castilla_y_leon.default_nuts'),
            'url_placsp' => $record['enlace_de_publicacion'] ?? $record['ENLACE'] ?? null,
            'fecha_adjudicacion' => $this->parseDate(
                $record['fecha_aprobacion_de_gasto'] ?? $record['FECHA_ADJUDICACION']
                ?? $record['fecha_adjudicacion'] ?? null
            ),
            'fecha_formalizacion' => $this->parseDate(
                $record['fecha_formalizacion'] ?? $record['FECHA_FORMALIZACION'] ?? null
            ),
            'duracion' => $duracion,
            'num_ofertas' => $this->parseInteger($record['no_peticion_de_ofertas'] ?? null),
            'es_menor' => $this->isContratoMenor($record),
        ];

        return $data;
    }

    private function parseImporte(?string $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = trim($value);

        // Formato europeo: punto como miles, coma como decimal
        if (preg_match('/^\d{1,3}(\.\d{3})*(,\d+)?$/', $value)) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        } else {
            $value = str_replace(',', '.', $value);
        }

        $float = (float) $value;

        return $float > 0 ? $float : null;
    }

    private function parseDate(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            $date = new \DateTimeImmutable($value);

            return $date->format('Y-m-d');
        } catch (\Exception) {
            $parts = explode('/', $value);
            if (count($parts) === 3) {
                try {
                    $date = new \DateTimeImmutable("{$parts[2]}-{$parts[1]}-{$parts[0]}");

                    return $date->format('Y-m-d');
                } catch (\Exception) {
                    return null;
                }
            }

            return null;
        }
    }

    private function parseInteger(?string $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value ?: null;
    }

    private function parseDuracion(array $record): ?string
    {
        $meses = $record['plazo_de_ejecucion_meses'] ?? null;
        $dias = $record['plazo_de_ejecucion_dias'] ?? null;

        $parts = [];
        if (! empty($meses) && $meses !== '0') {
            $parts[] = "{$meses} meses";
        }
        if (! empty($dias) && $dias !== '0') {
            $parts[] = "{$dias} dias";
        }

        return ! empty($parts) ? implode(' ', $parts) : null;
    }

    private function cleanNif(?string $nif): ?string
    {
        if ($nif === null || $nif === '') {
            return null;
        }

        $nif = mb_strtoupper(trim($nif));

        if (mb_strlen($nif) < 5) {
            return null;
        }

        return $nif;
    }

    private function isContratoMenor(array $record): bool
    {
        $procedimiento = $record['procedimiento_de_adjudicacion'] ?? $record['PROCEDIMIENTO'] ?? $record['procedimiento'] ?? '';

        return mb_stripos($procedimiento, 'menor') !== false
            || mb_stripos($procedimiento, 'Menor') !== false;
    }
}
