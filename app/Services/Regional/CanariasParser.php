<?php

declare(strict_types=1);

namespace App\Services\Regional;

class CanariasParser
{
    private const TIPO_CONTRATO_MAP = [
        'Servicios' => 'Servicios',
        'Suministros' => 'Suministros',
        'Suministro' => 'Suministros',
        'Obras' => 'Obras',
        'Obra' => 'Obras',
        'Gestión de servicios públicos' => 'Gestión de Servicios Públicos',
        'Concesión de obras' => 'Concesión de Obras Públicas',
        'Concesión de obras públicas' => 'Concesión de Obras Públicas',
        'Concesión de servicios' => 'Gestión de Servicios Públicos',
        'Patrimonial' => 'Patrimonial',
        'Administrativo especial' => 'Administrativo especial',
        'Privado' => 'Privado',
        'Mixto' => 'Mixto',
    ];

    /**
     * Parsea un registro CSV de contratos de Canarias.
     *
     * @param  array<string, string>  $record
     * @return array<string, mixed>|null
     */
    public function parse(array $record): ?array
    {
        $expediente = $record['expediente_numero'] ?? $record['EXPEDIENTE_NUMERO'] ?? null;

        if (empty($expediente)) {
            return null;
        }

        // Limpiar caracteres especiales del expediente para placsp_id
        $expedienteLimpio = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $expediente);
        $placspId = "CAN-{$expedienteLimpio}";

        // Organismo
        $organoNombre = $record['entidad_adjudicadora'] ?? $record['ENTIDAD_ADJUDICADORA'] ?? null;
        $nifOrgano = $record['entidad_adjudicadora_nif'] ?? $record['ENTIDAD_ADJUDICADORA_NIF'] ?? null;

        if (empty($nifOrgano) && ! empty($organoNombre)) {
            $nifOrgano = 'CAN-'.mb_strtoupper(mb_substr(md5($organoNombre), 0, 8));
        }

        if (empty($nifOrgano)) {
            return null;
        }

        // NIF adjudicatario: _U significa desconocido
        $nifAdj = $this->cleanSpecialValue(
            $record['adjudicataria_nif'] ?? $record['ADJUDICATARIA_NIF'] ?? null
        );

        $data = [
            'placsp_id' => $placspId,
            'expediente' => $expediente,
            'objeto' => $this->cleanSpecialValue(
                $record['objeto_contrato'] ?? $record['contrato_objeto']
                ?? $record['CONTRATO_OBJETO'] ?? $record['OBJETO_CONTRATO'] ?? null
            ),
            'nif_organo' => $nifOrgano,
            'nombre_organo' => $organoNombre,
            'tipo_contrato' => $this->mapTipoContrato(
                $record['contrato_tipo'] ?? $record['CONTRATO_TIPO'] ?? null
            ),
            'procedimiento' => $this->cleanSpecialValue(
                $record['procedimiento_contratacion'] ?? $record['PROCEDIMIENTO_CONTRATACION'] ?? null
            ),
            'importe_adjudicacion' => $this->parseImporte(
                $record['importe_ofertado'] ?? $record['IMPORTE_OFERTADO'] ?? null
            ),
            'moneda' => 'EUR',
            'nif_adjudicatario' => $this->cleanNif($nifAdj),
            'nombre_adjudicatario' => null,
            'cpv' => $this->cleanSpecialValue(
                $record['clasificacion_cpv'] ?? $record['CLASIFICACION_CPV'] ?? null
            ),
            'nuts' => config('contratacion.regional.canarias.default_nuts'),
            'url_placsp' => $this->cleanSpecialValue(
                $record['licitacion_enlace'] ?? $record['LICITACION_ENLACE'] ?? null
            ),
            'estado' => $this->cleanSpecialValue(
                $record['licitacion_estado'] ?? $record['LICITACION_ESTADO'] ?? null
            ),
            'fecha_adjudicacion' => $this->parseDate(
                $record['adjudicacion_fecha'] ?? $record['ADJUDICACION_FECHA'] ?? null
            ),
            'fecha_publicacion' => $this->parseDate(
                $record['publicacion_fecha'] ?? $record['PUBLICACION_FECHA'] ?? null
            ),
            'es_menor' => $this->isContratoMenor($record),
        ];

        return $data;
    }

    /**
     * Limpia valores especiales de Canarias: _U y _Z significan null.
     */
    private function cleanSpecialValue(?string $value): ?string
    {
        if ($value === null || $value === '' || $value === '_U' || $value === '_Z') {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private function parseImporte(?string $value): ?float
    {
        $value = $this->cleanSpecialValue($value);
        if ($value === null) {
            return null;
        }

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
        $value = $this->cleanSpecialValue($value);
        if ($value === null) {
            return null;
        }

        try {
            $date = new \DateTimeImmutable($value);

            return $date->format('Y-m-d');
        } catch (\Exception) {
            // Intentar formato dd/mm/yyyy
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

    private function mapTipoContrato(?string $tipo): ?string
    {
        if ($tipo === null || $tipo === '') {
            return null;
        }

        return self::TIPO_CONTRATO_MAP[$tipo] ?? $tipo;
    }

    private function isContratoMenor(array $record): bool
    {
        $procedimiento = $record['procedimiento_contratacion'] ?? $record['PROCEDIMIENTO_CONTRATACION'] ?? '';

        return mb_stripos($procedimiento, 'menor') !== false;
    }
}
