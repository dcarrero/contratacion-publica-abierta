<?php

declare(strict_types=1);

namespace App\Services\Regional;

/**
 * Parser para los datasets CSV de contratación de la Región de Murcia (CARM).
 *
 * Dos series de ficheros CSV con URLs directas y sin autenticación:
 *
 * Contratos mayores (excl. menores) — 2019 a la actualidad:
 *   https://datosabiertos.carm.es/odata/transparencia/contratosOD{AÑO}.csv
 *   Columnas: ejercicio, codinscripcion, tipocontrato, procedimiento, objeto,
 *             importlicitacion, importadjudicacion, adjudicatariodescripcion,
 *             adjudicatariocodigo, fechaformalizacion, duracion, organo,
 *             cpvcodigo, cpvdescripcion, nummodificaciones, codigoOrgano, fechaInicio
 *
 * Contratos menores — 2022 a la actualidad:
 *   https://datosabiertos.carm.es/odata/Hacienda/CONTRA_ContratosMenores_{AÑO}.csv
 *   Columnas: CODEXPEDIENTE, OBJETO_CONTRATO_MENOR, UNIDADCODIGO, UNIDADDESCRIPCION,
 *             CENTROCODIGO, CENTRODESCRIPCION, TIPOCONTRATO, CPVCODIGO, CPVDESCRIPCION,
 *             ADJUDICATARIOCODIGO, ADJUDICATARIODESCRIPCION, FECHACONTABPAGO,
 *             IMPORTECONTABPAGO, EJERCICIONUM, TRIMESTRENUM
 *
 * El campo NUTS no está en el CSV — se infiere como ES62 (Región de Murcia).
 * No hay NIF del organismo en los ficheros — se usa un NIF sintético basado en
 * el nombre/código del órgano.
 */
class MurciaParser
{
    private const TIPO_CONTRATO_MAP = [
        'SERVICIOS' => 'Servicios',
        'SUMINISTROS' => 'Suministros',
        'OBRAS' => 'Obras',
        'ADMINISTRATIVO ESPECIAL' => 'Administrativo especial',
        'PRIVADO' => 'Privado',
        'PATRIMONIAL' => 'Patrimonial',
        'GESTION SERVICIOS PUBLICOS' => 'Gestión de Servicios Públicos',
        'CONCESION OBRAS' => 'Concesión de Obras Públicas',
        'CONCESION SERVICIOS' => 'Gestión de Servicios Públicos',
    ];

    private const PROCEDIMIENTO_MAP = [
        'ABIERTO' => 'Abierto',
        'ABIERTO SIMPLIFICADO' => 'Abierto simplificado',
        'NEGOCIADO SIN PUBLICIDAD' => 'Negociado sin publicidad',
        'NEGOCIADO CON PUBLICIDAD' => 'Negociado con publicidad',
        'RESTRINGIDO' => 'Restringido',
        'DIALOGO COMPETITIVO' => 'Diálogo competitivo',
        'ASOCIACION PARA LA INNOVACION' => 'Asociación para la innovación',
        'CONTRATO MENOR' => 'Contrato menor',
    ];

    /**
     * Parsea un registro CSV de contratos mayores de la CARM.
     *
     * Fuente: contratosOD{AÑO}.csv
     * Separador: coma. Encoding: UTF-8.
     *
     * @param  array<string, string>  $record
     * @return array<string, mixed>|null
     */
    public function parseMayor(array $record): ?array
    {
        $codInscripcion = trim($record['codinscripcion'] ?? '');
        $ejercicio = trim($record['ejercicio'] ?? '');

        if ($codInscripcion === '') {
            return null;
        }

        $placspId = 'MUR-'.$ejercicio.'-'.$codInscripcion;

        $organoNombre = trim($record['organo'] ?? '');
        $codigoOrgano = trim($record['codigoOrgano'] ?? '');

        if ($organoNombre === '' && $codigoOrgano === '') {
            return null;
        }

        // NIF sintético basado en código de órgano si existe, si no en nombre
        $nifBase = $codigoOrgano !== '' ? $codigoOrgano : $organoNombre;
        $nifOrgano = 'MUR-'.mb_strtoupper(mb_substr(md5($nifBase), 0, 8));

        $importeLicitacion = $this->parseImporte($record['importlicitacion'] ?? null);
        $importeAdjudicacion = $this->parseImporte($record['importadjudicacion'] ?? null);

        $nifAdj = $this->cleanNif($record['adjudicatariocodigo'] ?? null);
        $nombreAdj = $this->cleanEmpty($record['adjudicatariodescripcion'] ?? null);

        return [
            'placsp_id' => $placspId,
            'expediente' => $codInscripcion,
            'objeto' => $this->cleanEmpty($record['objeto'] ?? null),
            'nif_organo' => $nifOrgano,
            'nombre_organo' => $organoNombre !== '' ? $organoNombre : null,
            'tipo_contrato' => $this->mapTipoContrato(trim($record['tipocontrato'] ?? '')),
            'procedimiento' => $this->mapProcedimiento(trim($record['procedimiento'] ?? '')),
            'importe_licitacion' => $importeLicitacion,
            'importe_adjudicacion' => $importeAdjudicacion,
            'moneda' => 'EUR',
            'nif_adjudicatario' => $nifAdj,
            'nombre_adjudicatario' => $nombreAdj,
            'cpv' => $this->cleanEmpty($record['cpvcodigo'] ?? null),
            'nuts' => config('contratacion.regional.murcia.default_nuts', 'ES62'),
            'url_placsp' => null, // No hay URL directa en este CSV
            'fecha_formalizacion' => $this->parseDate($record['fechaformalizacion'] ?? null),
            'fecha_publicacion' => $this->parseDate($record['fechaInicio'] ?? null),
            'duracion' => $this->cleanEmpty($record['duracion'] ?? null),
            'es_menor' => false,
        ];
    }

    /**
     * Parsea un registro CSV de contratos menores de la CARM.
     *
     * Fuente: CONTRA_ContratosMenores_{AÑO}.csv
     * Separador: coma. Encoding: UTF-8.
     *
     * @param  array<string, string>  $record
     * @return array<string, mixed>|null
     */
    public function parseMenor(array $record): ?array
    {
        $codExpediente = trim($record['CODEXPEDIENTE'] ?? '');
        $ejercicio = trim($record['EJERCICIONUM'] ?? '');
        $trimestre = trim($record['TRIMESTRENUM'] ?? '');

        if ($codExpediente === '') {
            return null;
        }

        // Hacer el placsp_id único usando expediente + trimestre (puede haber pagos múltiples)
        $placspId = 'MUR-MEN-'.$ejercicio.'-'.$codExpediente.($trimestre !== '' ? '-T'.$trimestre : '');

        $unidadCodigo = trim($record['UNIDADCODIGO'] ?? '');
        $unidadDesc = trim($record['UNIDADDESCRIPCION'] ?? '');
        $centroDesc = trim($record['CENTRODESCRIPCION'] ?? '');

        // Nombre del órgano: preferir descripción de unidad, fallback a centro
        $organoNombre = $unidadDesc !== '' ? $unidadDesc : $centroDesc;
        if ($organoNombre === '') {
            return null;
        }

        // NIF sintético basado en código de unidad si existe
        $nifBase = $unidadCodigo !== '' ? $unidadCodigo : $organoNombre;
        $nifOrgano = 'MUR-'.mb_strtoupper(mb_substr(md5($nifBase), 0, 8));

        $importe = $this->parseImporte($record['IMPORTECONTABPAGO'] ?? null);

        $nifAdj = $this->cleanNif($record['ADJUDICATARIOCODIGO'] ?? null);
        $nombreAdj = $this->cleanEmpty($record['ADJUDICATARIODESCRIPCION'] ?? null);

        return [
            'placsp_id' => $placspId,
            'expediente' => $codExpediente,
            'objeto' => $this->cleanEmpty($record['OBJETO_CONTRATO_MENOR'] ?? null),
            'nif_organo' => $nifOrgano,
            'nombre_organo' => $organoNombre,
            'tipo_contrato' => $this->mapTipoContrato(trim($record['TIPOCONTRATO'] ?? '')),
            'procedimiento' => 'Contrato menor',
            'importe_adjudicacion' => $importe,
            'moneda' => 'EUR',
            'nif_adjudicatario' => $nifAdj,
            'nombre_adjudicatario' => $nombreAdj,
            'cpv' => $this->cleanEmpty($record['CPVCODIGO'] ?? null),
            'nuts' => config('contratacion.regional.murcia.default_nuts', 'ES62'),
            'url_placsp' => null,
            'fecha_adjudicacion' => $this->parseDate($record['FECHACONTABPAGO'] ?? null),
            'fecha_publicacion' => null,
            'es_menor' => true,
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
            // Puede venir con punto decimal directo
            $value = str_replace(',', '.', $value);
        }

        $float = (float) $value;

        return $float > 0 ? $float : null;
    }

    private function parseDate(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        // Formato DD/MM/YYYY
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $value, $m)) {
            try {
                $date = new \DateTimeImmutable("{$m[3]}-{$m[2]}-{$m[1]}");

                return $date->format('Y-m-d');
            } catch (\Exception) {
                return null;
            }
        }

        // Formato ISO o cualquier otro
        try {
            $date = new \DateTimeImmutable($value);

            return $date->format('Y-m-d');
        } catch (\Exception) {
            return null;
        }
    }

    private function cleanNif(?string $nif): ?string
    {
        if ($nif === null || trim($nif) === '') {
            return null;
        }

        $nif = mb_strtoupper(trim($nif));

        // UTE: varios NIF unidos por "/" → tomar el primero (miembro principal)
        if (str_contains($nif, '/')) {
            $nif = trim(explode('/', $nif)[0]);
        }

        // Descartar valores que no son NIF/CIF válidos (nombres, basura) o que exceden la columna
        if (mb_strlen($nif) < 5 || mb_strlen($nif) > 20) {
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
