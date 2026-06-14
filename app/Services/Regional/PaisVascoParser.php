<?php

declare(strict_types=1);

namespace App\Services\Regional;

class PaisVascoParser
{
    /**
     * Parsea un registro JSON del catálogo anual de contrataciones de Euskadi.
     *
     * Los JSON anuales están en:
     * https://opendata.euskadi.eus/contenidos/ds_contrataciones/contrataciones_admin_{AÑO}/opendata/contratos.json
     *
     * El JSON NO contiene importes ni NIF adjudicatario — esos datos están en el XML
     * individual de cada contrato (campo dataXML).
     *
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>|null
     */
    public function parse(array $record): ?array
    {
        $expediente = $record['contratacion_expediente'] ?? null;

        if (empty($expediente)) {
            return null;
        }

        // Generar placsp_id sintético
        $placspId = 'EUSK-'.$expediente;

        // NIF del organismo: extraer de "P4800300H - Ayuntamiento de Amorebieta-Etxano"
        $entidadImpulsora = $record['contratacion_entidad_impulsora'] ?? '';
        $nifOrgano = null;
        $organoNombre = $record['contratacion_poder_adjudicador_titulo']
            ?? $record['institution'] ?? null;

        if (! empty($entidadImpulsora) && str_contains($entidadImpulsora, ' - ')) {
            $parts = explode(' - ', $entidadImpulsora, 2);
            $nifCandidate = trim($parts[0]);
            if (mb_strlen($nifCandidate) >= 5 && mb_strlen($nifCandidate) <= 20) {
                $nifOrgano = mb_strtoupper($nifCandidate);
            }
            if (empty($organoNombre) && ! empty($parts[1])) {
                $organoNombre = trim($parts[1]);
            }
        }

        if (empty($nifOrgano) && ! empty($organoNombre)) {
            $nifOrgano = 'EUSK-'.mb_strtoupper(mb_substr(md5($organoNombre), 0, 8));
        }

        if (empty($nifOrgano)) {
            return null;
        }

        $esMenor = ($record['contratacion_contrato_menor'] ?? '') === 'true';

        $data = [
            'placsp_id' => $placspId,
            'expediente' => $expediente,
            'objeto' => $record['contratacion_objeto_contrato']
                ?? $record['contratacion_titulo_contrato']
                ?? $record['documentName'] ?? null,
            'nif_organo' => $nifOrgano,
            'nombre_organo' => $organoNombre,
            'tipo_contrato' => $this->cleanEmpty($record['contratacion_tipo_contrato'] ?? null),
            'procedimiento' => $esMenor ? 'Contrato menor' : null,
            'estado' => $this->mapEstado($record['contratacion_estado_tramitacion'] ?? null),
            'moneda' => 'EUR',
            'nuts' => config('contratacion.regional.pais_vasco.default_nuts'),
            'url_placsp' => $record['physicalUrl'] ?? $record['friendlyUrl'] ?? null,
            'fecha_publicacion' => $this->parseDate(
                $record['contratacion_fecha_de_publicacion_documento'] ?? null
            ),
            'es_menor' => $esMenor,
            // dataXML URL para enriquecimiento futuro (importes, adjudicatario)
            'data_xml_url' => $this->cleanEmpty($record['dataXML'] ?? null),
        ];

        return $data;
    }

    /**
     * Parsea los datos detallados del XML de un contrato de Euskadi.
     *
     * Soporta dos formatos:
     * - Nuevo (2021+): tags semánticos (<contractingAnnouncement>, <tenderBudgetWithoutTaxes>, etc.)
     * - Antiguo (2018-2020): formato <item name="..."><value><![CDATA[...]]></value></item>
     *
     * @param  string  $xml  Raw XML string
     * @return array<string, mixed> Campos adicionales para merge
     */
    public function parseXmlDetail(string $xml): array
    {
        // Detectar formato por presencia de tag raíz
        if (str_contains($xml, '<contractingAnnouncement') || str_contains($xml, '<tenderBudgetWithoutTaxes')) {
            return $this->parseXmlDetailNew($xml);
        }

        if (str_contains($xml, '<record') && str_contains($xml, 'item name=')) {
            return $this->parseXmlDetailLegacy($xml);
        }

        return [];
    }

    /**
     * Formato nuevo (2021+): tags semánticos XML.
     */
    private function parseXmlDetailNew(string $xml): array
    {
        $data = [];

        // Importes de licitación
        $data['importe_licitacion'] = $this->extractXmlFloat($xml, 'tenderBudgetWithoutTaxes');
        $data['importe_licitacion_con_iva'] = $this->extractXmlFloat($xml, 'tenderBudgetWithTaxes');

        // Importes de adjudicación (de resolutions)
        $data['importe_adjudicacion'] = $this->extractXmlFloat($xml, 'priceWithoutVAT')
            ?? $this->extractXmlFloat($xml, 'awardBudgetWithoutTaxes');
        $data['importe_adjudicacion_con_iva'] = $this->extractXmlFloat($xml, 'priceWithVAT')
            ?? $this->extractXmlFloat($xml, 'awardBudgetWithTaxes');

        // Adjudicatario
        if (preg_match('/<businessCif[^>]*>(.*?)<\/businessCif>/s', $xml, $m)) {
            $nif = $this->cleanNif(html_entity_decode(trim($m[1])));
            if ($nif !== null) {
                $data['nif_adjudicatario'] = $nif;
            }
        }
        if (preg_match('/<businessName[^>]*>(.*?)<\/businessName>/s', $xml, $m)) {
            $data['nombre_adjudicatario'] = html_entity_decode(trim($m[1]));
        }

        // NUTS
        if (preg_match('/<code[^>]*desc="[^"]*NUTS[^"]*"[^>]*>(ES\d+)<\/code>/s', $xml, $m)) {
            $data['nuts'] = trim($m[1]);
        } elseif (preg_match('/<nutsCode[^>]*>(ES\d+)<\/nutsCode>/s', $xml, $m)) {
            $data['nuts'] = trim($m[1]);
        }

        // Tipo de contrato
        if (preg_match('/<contractingType[^>]*desc="[^"]*"[^>]*>(.*?)<\/contractingType>/s', $xml, $m)) {
            $data['tipo_contrato'] = html_entity_decode(trim($m[1]));
        }

        // Procedimiento
        if (preg_match('/<adjudicationProcedure[^>]*desc="[^"]*"[^>]*>(.*?)<\/adjudicationProcedure>/s', $xml, $m)) {
            $data['procedimiento'] = html_entity_decode(trim($m[1]));
        }

        // CPV
        if (preg_match('/<cpvCode[^>]*>(.*?)<\/cpvCode>/s', $xml, $m)) {
            $data['cpv'] = trim($m[1]);
        }

        // Fecha adjudicación
        if (preg_match('/<date[^>]*desc="Fecha adjudicaci[^"]*"[^>]*>(.*?)<\/date>/s', $xml, $m)) {
            $data['fecha_adjudicacion'] = $this->parseDate(trim($m[1]));
        }

        // Num ofertas
        if (preg_match('/<biddersNumber[^>]*>(\d+)<\/biddersNumber>/s', $xml, $m)) {
            $data['num_ofertas'] = (int) $m[1] ?: null;
        }

        // Duración
        if (preg_match('/<term[^>]*desc="Plazo"[^>]*>(\d+)<\/term>/s', $xml, $m)) {
            $termType = '';
            if (preg_match('/<termType[^>]*desc="[^"]*"[^>]*>(.*?)<\/termType>/s', $xml, $m2)) {
                $termType = ' '.html_entity_decode(trim($m2[1]));
            }
            $data['duracion'] = trim($m[1]).$termType;
        }

        return array_filter($data, fn ($v) => $v !== null && $v !== '');
    }

    /**
     * Formato antiguo (2018-2020): <item name="campo"><value><![CDATA[valor]]></value></item>
     */
    private function parseXmlDetailLegacy(string $xml): array
    {
        $data = [];

        // Importes de licitación (presupuesto)
        $data['importe_licitacion'] = $this->extractLegacyFloat($xml, 'contratacion_presupuesto_contrato_cab');
        $data['importe_licitacion_con_iva'] = $this->extractLegacyFloat($xml, 'contratacion_presupuesto_contrato_con_iva_cab');

        // Importes de adjudicación
        $data['importe_adjudicacion'] = $this->extractLegacyFloat($xml, 'precio')
            ?? $this->extractLegacyFloat($xml, 'contratacion_importe_definitivo');
        $data['importe_adjudicacion_con_iva'] = $this->extractLegacyFloat($xml, 'precioIVA');

        // Adjudicatario NIF
        $nif = $this->extractLegacyCdata($xml, 'contratacion_nifcif');
        if ($nif !== null) {
            $nif = $this->cleanNif($nif);
            if ($nif !== null) {
                $data['nif_adjudicatario'] = $nif;
            }
        }

        // Adjudicatario nombre
        $nombre = $this->extractLegacyCdata($xml, 'empresa');
        if ($nombre !== null) {
            $data['nombre_adjudicatario'] = $nombre;
        }

        // NUTS
        $nuts = $this->extractLegacyCdata($xml, 'contratacion_codigo_nuts');
        if ($nuts !== null && str_starts_with($nuts, 'ES')) {
            $data['nuts'] = $nuts;
        }

        // Tipo de contrato (viene como código numérico con valor textual)
        $tipoContrato = $this->extractLegacyNestedValue($xml, 'contratacion_tipo_contrato');
        if ($tipoContrato !== null) {
            $data['tipo_contrato'] = $tipoContrato;
        }

        // Procedimiento
        $procedimiento = $this->extractLegacyNestedValue($xml, 'contratacion_procedimiento');
        if ($procedimiento !== null) {
            $data['procedimiento'] = $procedimiento;
        }

        // CPV (nested: contratacion_codigo_cpv → contratacion_cpv)
        $cpv = $this->extractLegacyCdata($xml, 'contratacion_cpv');
        if ($cpv !== null) {
            $data['cpv'] = $cpv;
        }

        // Fecha adjudicación
        $fechaAdj = $this->extractLegacyCdata($xml, 'contratacion_fecha_adjudicacion_definitiva');
        if ($fechaAdj !== null) {
            $data['fecha_adjudicacion'] = $this->parseDate($fechaAdj);
        }

        // Num ofertas
        $numOfertas = $this->extractLegacyCdata($xml, 'contratacion_num_licitadores');
        if ($numOfertas !== null && is_numeric($numOfertas)) {
            $data['num_ofertas'] = (int) $numOfertas ?: null;
        }

        // Duración
        $duracion = $this->extractLegacyCdata($xml, 'contratacion_duracion_contrato_plazo_ejecucion');
        if ($duracion !== null) {
            $data['duracion'] = $duracion;
        }

        return array_filter($data, fn ($v) => $v !== null && $v !== '');
    }

    private function parseDate(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        // Formato dd/mm/yyyy o dd/mm/yyyy HH:mm
        if (preg_match('#^(\d{2})/(\d{2})/(\d{4})#', $value, $m)) {
            return "{$m[3]}-{$m[2]}-{$m[1]}";
        }

        try {
            $date = new \DateTimeImmutable($value);

            return $date->format('Y-m-d');
        } catch (\Exception) {
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

    private function cleanEmpty(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    private function mapEstado(?string $estado): ?string
    {
        if ($estado === null || $estado === '') {
            return null;
        }

        return match (true) {
            str_contains($estado, 'Formalización') => 'Formalizado',
            str_contains($estado, 'Adjudicación') => 'Adjudicado',
            str_contains($estado, 'Abierto') => 'Abierto',
            str_contains($estado, 'cerrado') => 'Plazo cerrado',
            str_contains($estado, 'Desierto') => 'Desierto',
            str_contains($estado, 'Anulación') => 'Anulado',
            default => $estado,
        };
    }

    /**
     * Extrae un CDATA simple del formato legacy: <item name="campo"><value><![CDATA[valor]]></value></item>
     */
    private function extractLegacyCdata(string $xml, string $itemName): ?string
    {
        // Match: <item name="campo" ...><value><![CDATA[valor]]></value>
        $pattern = '/<item\s+name="'.preg_quote($itemName, '/').'"[^>]*><value><!\[CDATA\[(.*?)\]\]><\/value>/s';
        if (preg_match($pattern, $xml, $m)) {
            $value = trim($m[1]);

            return $value !== '' ? $value : null;
        }

        return null;
    }

    /**
     * Extrae un float del formato legacy.
     */
    private function extractLegacyFloat(string $xml, string $itemName): ?float
    {
        $value = $this->extractLegacyCdata($xml, $itemName);
        if ($value === null) {
            return null;
        }

        // Formato europeo: 431.295 o 521.866,95
        $value = str_replace('.', '', $value);
        $value = str_replace(',', '.', $value);
        $float = (float) $value;

        return $float > 0 ? $float : null;
    }

    /**
     * Extrae el valor textual de un campo nested legacy.
     * Ejemplo: <item name="contratacion_tipo_contrato">...<item name="valor"><value><![CDATA[Servicios]]></value></item>...
     */
    private function extractLegacyNestedValue(string $xml, string $itemName): ?string
    {
        // Buscar directamente: campo → ... → item name="valor" → CDATA
        $pattern = '/<item\s+name="'.preg_quote($itemName, '/').'"[^>]*>.*?<item\s+name="valor"[^>]*><value><!\[CDATA\[(.*?)\]\]>/s';
        if (preg_match($pattern, $xml, $m)) {
            return trim($m[1]) ?: null;
        }

        return null;
    }

    private function extractXmlFloat(string $xml, string $tag): ?float
    {
        if (preg_match("/<{$tag}[^>]*>(.*?)<\/{$tag}>/s", $xml, $m)) {
            $value = html_entity_decode(trim($m[1]));
            // Formato europeo: 431.295 o 521.866,95
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
            $float = (float) $value;

            return $float > 0 ? $float : null;
        }

        return null;
    }
}
