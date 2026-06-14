<?php

declare(strict_types=1);

namespace App\Services\Regional;

class ValenciaParser
{
    private const TIPO_CONTRATO_MAP = [
        'Servicios' => 'Servicios',
        'Suministros' => 'Suministros',
        'Suministro' => 'Suministros',
        'Obras' => 'Obras',
        'Obra' => 'Obras',
        'Gestión de Servicios Públicos' => 'Gestión de Servicios Públicos',
        'Gestió de Serveis Públics' => 'Gestión de Servicios Públicos',
        'Concesión de Obras' => 'Concesión de Obras Públicas',
        'Concesión de Servicios' => 'Gestión de Servicios Públicos',
        'Concessió de Serveis' => 'Gestión de Servicios Públicos',
        'Concessió d\'Obres' => 'Concesión de Obras Públicas',
        'Patrimonial' => 'Patrimonial',
        'Administrativo especial' => 'Administrativo especial',
        'Privado' => 'Privado',
        'Mixto' => 'Mixto',
        'Serveis' => 'Servicios',
        'Subministraments' => 'Suministros',
        'Obres' => 'Obras',
    ];

    /**
     * Parsea un registro CSV de contratos de la Comunitat Valenciana.
     *
     * @param  array<string, string>  $record
     * @return array<string, mixed>|null
     */
    public function parse(array $record): ?array
    {
        $expediente = $record['EXPEDIENTE'] ?? $record['expediente'] ?? null;

        if (empty($expediente)) {
            return null;
        }

        $ejercicio = $record['EJERCICIO'] ?? $record['ejercicio'] ?? date('Y');
        $placspId = "VAL-{$ejercicio}-{$expediente}";

        // Organismo: combinar conselleria + unidad
        $conselleria = $record['CONSELLERIA_ENT_ADJUD'] ?? $record['conselleria_ent_adjud'] ?? null;
        $unidad = $record['UNIDAD'] ?? $record['unidad'] ?? null;
        $organoNombre = $conselleria;
        if (! empty($unidad) && $unidad !== $conselleria) {
            $organoNombre = $conselleria.' - '.$unidad;
        }

        // NIF del organismo: buscar en campo o generar sintético
        $nifOrgano = $record['NIF_ENT_ADJUD'] ?? $record['nif_ent_adjud'] ?? null;
        if (empty($nifOrgano) && ! empty($organoNombre)) {
            $nifOrgano = 'VAL-'.mb_strtoupper(mb_substr(md5($organoNombre), 0, 8));
        }

        if (empty($nifOrgano)) {
            return null;
        }

        $data = [
            'placsp_id' => $placspId,
            'expediente' => $expediente,
            'objeto' => $record['OBJETO'] ?? $record['objeto']
                ?? $record['DESCRIPCION'] ?? $record['descripcion'] ?? null,
            'nif_organo' => $nifOrgano,
            'nombre_organo' => $organoNombre,
            'tipo_contrato' => $this->mapTipoContrato(
                $record['TIPO'] ?? $record['tipo'] ?? null
            ),
            'procedimiento' => $record['PROCEDIMIENTO'] ?? $record['procedimiento'] ?? null,
            'importe_licitacion' => $this->parseImporte(
                $record['VALOR_ESTIMADO_SIN_IVA'] ?? $record['valor_estimado_sin_iva'] ?? null
            ),
            'importe_adjudicacion' => $this->parseImporte(
                $record['IMP_ADJUD_SIN_IVA'] ?? $record['imp_adjud_sin_iva'] ?? null
            ),
            'importe_adjudicacion_con_iva' => $this->parseImporte(
                $record['IMP_TOTAL_ADJUD'] ?? $record['imp_total_adjud'] ?? null
            ),
            'moneda' => 'EUR',
            'nif_adjudicatario' => $this->cleanNif(
                $record['CIF_NIF_ENMASCARADO'] ?? $record['cif_nif_enmascarado']
                ?? $record['NIF_ADJUDICATARIO'] ?? $record['nif_adjudicatario'] ?? null
            ),
            'nombre_adjudicatario' => $record['NOMBRE_O_RAZON_SOCIAL'] ?? $record['nombre_o_razon_social']
                ?? $record['ADJUDICATARIO'] ?? $record['adjudicatario'] ?? null,
            'cpv' => $this->cleanEmpty($record['CODIGO_CPV'] ?? $record['codigo_cpv'] ?? null),
            'nuts' => $this->cleanEmpty($record['CODIGO_NUTS'] ?? $record['codigo_nuts'] ?? null)
                ?? config('contratacion.regional.valencia.default_nuts'),
            'num_ofertas' => $this->parseInteger(
                $record['NUM_LICITADORES'] ?? $record['num_licitadores'] ?? null
            ),
            'url_placsp' => $this->cleanEmpty($record['URL_LICITACION'] ?? $record['url_licitacion'] ?? null),
            'fecha_adjudicacion' => $this->parseDate(
                $record['FECHA_ADJUDICACION'] ?? $record['fecha_adjudicacion'] ?? null
            ),
            'fecha_formalizacion' => $this->parseDate(
                $record['FECHA_FORMALIZACION'] ?? $record['fecha_formalizacion'] ?? null
            ),
            'fecha_publicacion' => $this->parseDate(
                $record['FECHA_PUBLICACION'] ?? $record['fecha_publicacion'] ?? null
            ),
            'estado' => $record['ESTADO'] ?? $record['estado'] ?? null,
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

    private function isContratoMenor(array $record): bool
    {
        $clase = $record['CLASE_DE_CONTRATO'] ?? $record['clase_de_contrato'] ?? '';
        $procedimiento = $record['PROCEDIMIENTO'] ?? $record['procedimiento'] ?? '';

        return mb_stripos($clase, 'menor') !== false
            || mb_stripos($procedimiento, 'menor') !== false;
    }
}
