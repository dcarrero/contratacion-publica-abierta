<?php

declare(strict_types=1);

namespace App\Services\Regional;

class AndaluciaParser
{
    /**
     * Mapeo de tipos de contrato texto → código estándar.
     *
     * @var array<string, string>
     */
    private const TIPO_CONTRATO_MAP = [
        'Suministros' => 'Suministros',
        'Suministro' => 'Suministros',
        'Servicios' => 'Servicios',
        'Servicio' => 'Servicios',
        'Obras' => 'Obras',
        'Obra' => 'Obras',
        'Gestión de Servicios Públicos' => 'Gestión de Servicios Públicos',
        'Concesión de Obras' => 'Concesión de Obras Públicas',
        'Concesión de Servicios' => 'Gestión de Servicios Públicos',
        'Patrimonial' => 'Patrimonial',
        'Administrativo Especial' => 'Administrativo especial',
        'Privado' => 'Privado',
        'Mixto' => 'Mixto',
    ];

    /**
     * Parsea un registro CSV de contratos menores de Andalucía.
     *
     * @param  array<string, string>  $record
     * @return array<string, mixed>|null
     */
    public function parse(array $record, int $year = 0): ?array
    {
        $idExpediente = $record['ID_EXPEDIENTE'] ?? $record['id_expediente'] ?? null;
        $numExpediente = $record['NUM_EXPEDIENTE'] ?? $record['num_expediente'] ?? null;

        if (empty($idExpediente) && empty($numExpediente)) {
            return null;
        }

        // Generar placsp_id sintético
        $yearStr = $year > 0 ? (string) $year : date('Y');
        $idPart = $idExpediente ?? $numExpediente;
        $placspId = "ANDA-{$yearStr}-{$idPart}";

        // Organismo: sin NIF normalmente, usar nombre para generar sintético
        $organoNombre = $record['ORGANO_CONTRATACION'] ?? $record['organo_contratacion'] ?? null;
        $nifOrgano = $record['NIF_ORGANO'] ?? $record['nif_organo'] ?? null;

        if (empty($nifOrgano) && ! empty($organoNombre)) {
            // Generar NIF sintético basado en hash del nombre
            $nifOrgano = 'ANDA-'.mb_strtoupper(mb_substr(md5($organoNombre), 0, 8));
        }

        if (empty($nifOrgano)) {
            return null;
        }

        $data = [
            'placsp_id' => $placspId,
            'expediente' => $numExpediente ?? $idExpediente,
            'objeto' => $record['TITULO'] ?? $record['titulo'] ?? $record['OBJETO'] ?? $record['objeto'] ?? null,
            'nif_organo' => $nifOrgano,
            'nombre_organo' => $organoNombre,
            'tipo_contrato' => $this->mapTipoContrato(
                $record['TIPO_CONTRATO'] ?? $record['tipo_contrato'] ?? null
            ),
            'procedimiento' => 'Contrato menor',
            'importe_adjudicacion' => $this->parseImporte(
                $record['IMPORTE_ADJUDICACION_SIN_IVA'] ?? $record['importe_adjudicacion_sin_iva']
                ?? $record['IMPORTE_SIN_IVA'] ?? $record['importe_sin_iva'] ?? null
            ),
            'importe_adjudicacion_con_iva' => $this->parseImporte(
                $record['IMPORTE_ADJUDICACION_CON_IVA'] ?? $record['importe_adjudicacion_con_iva']
                ?? $record['IMPORTE_CON_IVA'] ?? $record['importe_con_iva'] ?? null
            ),
            'moneda' => 'EUR',
            'nif_adjudicatario' => $this->cleanNif(
                $record['NIF_ADJUDICATARIO'] ?? $record['nif_adjudicatario'] ?? null
            ),
            'nombre_adjudicatario' => $record['ADJUDICATARIO_DENOMINACION'] ?? $record['NOMBRE_ADJUDICATARIO']
                ?? $record['nombre_adjudicatario'] ?? $record['ADJUDICATARIO'] ?? $record['adjudicatario'] ?? null,
            'nuts' => $this->cleanEmpty($record['LUGAR_EJECUCION_CODIGO'] ?? $record['lugar_ejecucion_codigo'] ?? null)
                ?? config('contratacion.regional.andalucia.default_nuts'),
            'num_ofertas' => $this->parseInteger($record['NUM_LICITADORES_PRESENTADOS'] ?? $record['num_licitadores_presentados'] ?? null),
            'lugar_ejecucion' => $record['LUGAR_EJECUCION_DENOMINACION'] ?? $record['LUGAR_EJECUCION'] ?? $record['lugar_ejecucion'] ?? null,
            'estado' => $record['ESTADO'] ?? $record['estado'] ?? null,
            'fecha_adjudicacion' => $this->parseDate(
                $record['FECHA_ADJUDICACION'] ?? $record['fecha_adjudicacion'] ?? null
            ) ?? $this->parseDate(
                $record['FECHA_FORMALIZACION'] ?? $record['fecha_formalizacion'] ?? null
            ),
            'fecha_publicacion' => $this->parseDate(
                $record['FECHA_PUBLICACION'] ?? $record['fecha_publicacion'] ?? null
            ),
            'duracion' => $this->parseDuracion($record),
            'es_menor' => true,
        ];

        return $data;
    }

    private function parseImporte(?string $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        // Normalizar separadores: "1.234,56" → "1234.56"
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

    private function parseInteger(?string $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value ?: null;
    }

    private function parseDuracion(array $record): ?string
    {
        $duracion = $record['DURACION_CONTRATO'] ?? $record['DURACION'] ?? $record['duracion'] ?? null;
        $medida = $record['DURACION_MEDIDA'] ?? $record['duracion_medida'] ?? null;

        if (empty($duracion)) {
            return null;
        }

        if (! empty($medida)) {
            return "{$duracion} {$medida}";
        }

        return $duracion;
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
