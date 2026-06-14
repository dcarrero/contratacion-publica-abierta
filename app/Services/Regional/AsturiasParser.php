<?php

declare(strict_types=1);

namespace App\Services\Regional;

class AsturiasParser
{
    private const TIPO_CONTRATO_MAP = [
        'SUMINISTROS' => 'Suministros',
        'SERVICIOS' => 'Servicios',
        'OBRAS' => 'Obras',
        'GESTION DE SERVICIOS PUBLICOS' => 'Gestión de Servicios Públicos',
        'GESTION SERVICIOS PUBLICOS' => 'Gestión de Servicios Públicos',
        'CONCESION DE OBRAS' => 'Concesión de Obras Públicas',
        'CONCESION DE SERVICIOS' => 'Gestión de Servicios Públicos',
        'MIXTO' => 'Mixto',
        'PATRIMONIAL' => 'Patrimonial',
        'ADMINISTRATIVO ESPECIAL' => 'Administrativo especial',
        'PRIVADO' => 'Privado',
    ];

    private const PROCEDIMIENTO_MAP = [
        'DIRECTO' => 'Contrato menor',
        'ABIERTO' => 'Abierto',
        'ABIERTO SIMPLIFICADO' => 'Abierto simplificado',
        'ABIERTO SIMPLIFICADO ABREVIADO' => 'Abierto simplificado',
        'NEGOCIADO' => 'Negociado sin publicidad',
        'NEGOCIADO SIN PUBLICIDAD' => 'Negociado sin publicidad',
        'NEGOCIADO CON PUBLICIDAD' => 'Negociado con publicidad',
        'RESTRINGIDO' => 'Restringido',
        'OTROS' => 'Otros',
        'DIALOGO COMPETITIVO' => 'Diálogo competitivo',
    ];

    /**
     * Parsea un registro CSV de contratación centralizada de Asturias.
     * Delimitador: § (section sign). Encoding original: ISO-8859-1 (convertido a UTF-8 antes).
     *
     * @param  array<string, string>  $record
     * @return array<string, mixed>|null
     */
    public function parse(array $record): ?array
    {
        $inscripcion = trim($record['Nº INSCRIPCION'] ?? $record['N INSCRIPCION'] ?? '');

        if ($inscripcion === '') {
            return null;
        }

        $year = trim($record['AÑO'] ?? $record['ANO'] ?? '');
        $placspId = "AST-{$inscripcion}";

        // Organismo: sin NIF en el fichero, usar NIF sintético
        $organoNombre = trim($record['ORGANO CONTRATANTE'] ?? '');
        $enteNombre = trim($record['ENTE CONTRATANTE'] ?? '');
        $organoFull = $organoNombre;
        if (! empty($enteNombre) && $enteNombre !== $organoNombre) {
            $organoFull = $enteNombre.' - '.$organoNombre;
        }

        if (empty($organoNombre) && empty($enteNombre)) {
            return null;
        }

        $nifOrgano = 'AST-'.mb_strtoupper(mb_substr(md5($organoFull), 0, 8));

        // Importes: el fichero tiene IMP. ADJ. (CON IVA) e IMP. ADJ. IMPUESTO
        $importeConIva = $this->parseImporte($record['IMP. ADJ. (CON IVA)'] ?? null);
        $importeImpuesto = $this->parseImporte($record['IMP. ADJ. IMPUESTO'] ?? null);
        $importeLote = $this->parseImporte($record['IMP. ADJ. LOTE'] ?? null);

        // Importe sin IVA = con IVA - impuesto
        $importeSinIva = null;
        if ($importeConIva !== null && $importeImpuesto !== null) {
            $importeSinIva = round($importeConIva - $importeImpuesto, 2);
        } elseif ($importeConIva !== null) {
            // Fallback: estimar sin IVA con el % IVA
            $iva = $this->parseIva($record['IVA'] ?? null);
            if ($iva !== null && $iva > 0) {
                $importeSinIva = round($importeConIva / (1 + $iva / 100), 2);
            }
        }

        // CPV: en menores dice "MENORES", ignorar
        $cpv = trim($record['CODIGO CPV'] ?? '');
        if ($cpv === 'MENORES' || $cpv === '') {
            $cpv = null;
        }

        // Clasificación general
        $clasificacion = mb_strtoupper(trim($record['CLASIFICACION GENERAL'] ?? ''));
        $esMenor = in_array($clasificacion, ['MENOR', 'MENORES 5000'], true);

        // URL PLACSP
        $idPlace = trim($record['ID. PLACE'] ?? '');
        $urlPlacsp = null;
        if (! empty($idPlace)) {
            $urlPlacsp = "https://contrataciondelestado.es/wps/poc?uri=deeplink:detalle_licitacion;idEvl={$idPlace}";
        }

        return [
            'placsp_id' => $placspId,
            'expediente' => trim($record['Nº EXPEDIENTE ORGANO'] ?? $record['N EXPEDIENTE ORGANO'] ?? ''),
            'objeto' => trim($record['OBJETO'] ?? ''),
            'nif_organo' => $nifOrgano,
            'nombre_organo' => $organoFull,
            'tipo_contrato' => $this->mapTipoContrato(
                trim($record['CARACTERISTICAS CONTRATO'] ?? '')
            ),
            'procedimiento' => $this->mapProcedimiento(
                trim($record['PROC. ADJUDICACION'] ?? '')
            ),
            'importe_adjudicacion' => $importeSinIva,
            'importe_adjudicacion_con_iva' => $importeConIva ?? $importeLote,
            'moneda' => 'EUR',
            'nif_adjudicatario' => $this->extractNif($record),
            'nombre_adjudicatario' => $this->extractNombreAdjudicatario($record),
            'cpv' => $cpv,
            'nuts' => config('contratacion.regional.asturias.default_nuts'),
            'url_placsp' => $urlPlacsp,
            'estado' => $this->cleanEmpty($record['ESTADO CONTRATO'] ?? null),
            'fecha_adjudicacion' => $this->parseDate($record['F. ADJ.'] ?? null),
            'fecha_formalizacion' => $this->parseDate($record['F. FORMALIZACION'] ?? null),
            'fecha_publicacion' => $this->parseDate($record['F. DE ALTA'] ?? null),
            'es_menor' => $esMenor,
        ];
    }

    private function parseImporte(?string $value): ?float
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        // Formato europeo: "1.234,56" → "1234.56"
        if (preg_match('/^\d{1,3}(\.\d{3})*(,\d+)?$/', $value)) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        } else {
            $value = str_replace(',', '.', $value);
        }

        $float = (float) $value;

        return $float > 0 ? $float : null;
    }

    private function parseIva(?string $value): ?float
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = trim($value);
        $value = str_replace(',', '.', $value);

        $float = (float) $value;

        return $float > 0 ? $float : null;
    }

    private function parseDate(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        // Formato dd/mm/yyyy
        $parts = explode('/', $value);
        if (count($parts) === 3) {
            try {
                $date = new \DateTimeImmutable("{$parts[2]}-{$parts[1]}-{$parts[0]}");

                return $date->format('Y-m-d');
            } catch (\Exception) {
                return null;
            }
        }

        try {
            $date = new \DateTimeImmutable($value);

            return $date->format('Y-m-d');
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Extraer NIF del adjudicatario. 3 formatos:
     * - 2019-2021: col "CONTRATISTAS" = "NIF - NOMBRE"
     * - 2022: col con header roto, pero datos en misma posición
     * - 2023-2024: col "NIF/CIF CONTRATISTA" separada
     */
    private function extractNif(array $record): ?string
    {
        // Formato 2023+: columnas separadas
        $nif = $record['NIF/CIF CONTRATISTA'] ?? null;
        if (! empty($nif)) {
            return $this->cleanNif($nif);
        }

        // Formato 2019-2021: "CONTRATISTAS" = "NIF - NOMBRE"
        $contratistas = $record['CONTRATISTAS'] ?? null;
        if (! empty($contratistas)) {
            return $this->parseNifFromContratistas($contratistas);
        }

        // Formato 2022: header roto, buscar cualquier key que empiece por "NIF/CIF"
        foreach ($record as $key => $value) {
            if (str_starts_with($key, 'NIF/CIF') && ! empty($value)) {
                return $this->cleanNif($value);
            }
        }

        return null;
    }

    private function extractNombreAdjudicatario(array $record): ?string
    {
        // Formato 2023+
        $nombre = $record['RAZON SOCIAL CONTRATISTA'] ?? null;
        if (! empty($nombre)) {
            return $this->cleanEmpty($nombre);
        }

        // Formato 2022: header roto con coma
        foreach ($record as $key => $value) {
            if (str_starts_with($key, 'RAZON') && ! empty($value)) {
                return $this->cleanEmpty($value);
            }
        }

        // Formato 2019-2021: extraer de "CONTRATISTAS"
        $contratistas = $record['CONTRATISTAS'] ?? null;
        if (! empty($contratistas)) {
            return $this->parseNombreFromContratistas($contratistas);
        }

        return null;
    }

    private function parseNifFromContratistas(string $value): ?string
    {
        $value = trim($value);
        // Formato: "B29060381 - ZIMMER BIOMET SPAIN, S.L.U."
        if (preg_match('/^([A-Z0-9]{5,15})\s*-\s*/', $value, $m)) {
            return $this->cleanNif($m[1]);
        }

        return null;
    }

    private function parseNombreFromContratistas(string $value): ?string
    {
        $value = trim($value);
        // Formato: "B29060381 - ZIMMER BIOMET SPAIN, S.L.U."
        $pos = mb_strpos($value, ' - ');
        if ($pos !== false) {
            return $this->cleanEmpty(mb_substr($value, $pos + 3));
        }

        return null;
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

    private function cleanEmpty(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    private function mapTipoContrato(string $tipo): ?string
    {
        if ($tipo === '') {
            return null;
        }

        return self::TIPO_CONTRATO_MAP[mb_strtoupper($tipo)] ?? $tipo;
    }

    private function mapProcedimiento(string $proc): ?string
    {
        if ($proc === '') {
            return null;
        }

        return self::PROCEDIMIENTO_MAP[mb_strtoupper($proc)] ?? $proc;
    }
}
