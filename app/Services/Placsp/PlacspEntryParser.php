<?php

declare(strict_types=1);

namespace App\Services\Placsp;

use App\Models\Organismo;
use SimpleXMLElement;

class PlacspEntryParser
{
    /**
     * Indica si el último NIF fue resuelto por nombre de organismo (no por NIF/DIR3 directo).
     * El consumidor puede usar esto para marcar contratos con matching automático.
     */
    public bool $lastNifMatchedByName = false;

    /** @var array<string, string|null> Cache nombre→NIF para evitar queries repetidas */
    private array $nameNifCache = [];

    private const NAMESPACES = [
        'cbc' => 'urn:dgpe:names:draft:codice:schema:xsd:CommonBasicComponents-2',
        'cac' => 'urn:dgpe:names:draft:codice:schema:xsd:CommonAggregateComponents-2',
        'cbc-place-ext' => 'urn:dgpe:names:draft:codice-place-ext:schema:xsd:CommonBasicComponents-2',
        'cac-place-ext' => 'urn:dgpe:names:draft:codice-place-ext:schema:xsd:CommonAggregateComponents-2',
    ];

    /**
     * Parsea un entry Atom completo y devuelve array para ContratoImporter.
     * Devuelve null si el entry no contiene datos parseables.
     *
     * @return array<string, mixed>|null
     */
    public function parse(SimpleXMLElement $entry): ?array
    {
        $cfs = $this->getContractFolderStatus($entry);

        if ($cfs === null) {
            return null;
        }

        $this->registerNamespaces($cfs);

        $nifOrgano = $this->extractNifOrgano($cfs);

        if ($nifOrgano === null) {
            return null;
        }

        $entryId = (string) $entry->id;
        $entryLink = (string) ($entry->link['href'] ?? '');
        $entryUpdated = (string) $entry->updated;

        $data = [
            'placsp_id' => $this->buildPlacspId($entryId),
            'url_placsp' => html_entity_decode($entryLink),
            'url_xml' => $entryId,
            'uuid_ted' => $this->xpathString($cfs, 'cbc:UUID'),
            'id_plataforma' => $this->extractIdPlataforma($cfs),
            'expediente' => $this->xpathString($cfs, 'cbc:ContractFolderID'),
            'estado' => $this->xpathString($cfs, 'cbc-place-ext:ContractFolderStatusCode'),

            // Órgano contratante
            'nombre_organo' => $this->xpathString(
                $cfs,
                'cac-place-ext:LocatedContractingParty/cac:Party/cac:PartyName/cbc:Name'
            ),
            'nif_organo' => $nifOrgano,
            'dir3' => $this->extractDir3($cfs),
            'tipo_organo' => $this->xpathString(
                $cfs,
                'cac-place-ext:LocatedContractingParty/cbc:ContractingPartyTypeCode'
            ),
            'codigo_actividad' => $this->xpathString(
                $cfs,
                'cac-place-ext:LocatedContractingParty/cbc:ActivityCode'
            ),
            'url_perfil_placsp' => $this->extractBuyerProfileUri($cfs),

            // Contacto del órgano
            'contacto_nombre' => $this->xpathString(
                $cfs,
                'cac-place-ext:LocatedContractingParty/cac:Party/cac:Contact/cbc:Name'
            ),
            'contacto_telefono' => $this->xpathString(
                $cfs,
                'cac-place-ext:LocatedContractingParty/cac:Party/cac:Contact/cbc:Telephone'
            ),
            'contacto_email' => $this->xpathString(
                $cfs,
                'cac-place-ext:LocatedContractingParty/cac:Party/cac:Contact/cbc:ElectronicMail'
            ),

            // Dirección del órgano
            'direccion_organo' => $this->xpathString(
                $cfs,
                'cac-place-ext:LocatedContractingParty/cac:Party/cac:PostalAddress/cac:AddressLine/cbc:Line'
            ),
            'ciudad_organo' => $this->xpathString(
                $cfs,
                'cac-place-ext:LocatedContractingParty/cac:Party/cac:PostalAddress/cbc:CityName'
            ),
            'codigo_postal_organo' => $this->xpathString(
                $cfs,
                'cac-place-ext:LocatedContractingParty/cac:Party/cac:PostalAddress/cbc:PostalZone'
            ),

            // Datos del contrato
            'objeto' => $this->xpathString($cfs, 'cac:ProcurementProject/cbc:Name'),
            'tipo_contrato' => $this->xpathString($cfs, 'cac:ProcurementProject/cbc:TypeCode'),
            'subtipo_contrato' => $this->xpathString($cfs, 'cac:ProcurementProject/cbc:SubTypeCode'),
            'procedimiento' => $this->xpathString(
                $cfs,
                'cac:TenderingProcess/cbc:ProcedureCode'
            ),
            'urgencia' => $this->xpathString(
                $cfs,
                'cac:TenderingProcess/cbc:UrgencyCode'
            ),

            // Importes
            'importe_licitacion' => $this->xpathDecimal(
                $cfs,
                'cac:ProcurementProject/cac:BudgetAmount/cbc:TaxExclusiveAmount'
            ),
            'importe_licitacion_con_iva' => $this->xpathDecimal(
                $cfs,
                'cac:ProcurementProject/cac:BudgetAmount/cbc:TotalAmount'
            ),
            'importe_estimado' => $this->xpathDecimal(
                $cfs,
                'cac:ProcurementProject/cac:BudgetAmount/cbc:EstimatedOverallContractAmount'
            ),

            'duracion' => $this->extractDuracion($cfs),
            'cpv' => $this->xpathString(
                $cfs,
                'cac:ProcurementProject/cac:RequiredCommodityClassification/cbc:ItemClassificationCode'
            ),

            // Lugar de ejecución
            'nuts' => $this->xpathString(
                $cfs,
                'cac:ProcurementProject/cac:RealizedLocation/cbc:CountrySubentityCode'
            ),
            'lugar_ejecucion' => $this->xpathString(
                $cfs,
                'cac:ProcurementProject/cac:RealizedLocation/cbc:CountrySubentity'
            ),
            'ciudad_ejecucion' => $this->xpathString(
                $cfs,
                'cac:ProcurementProject/cac:RealizedLocation/cac:Address/cbc:CityName'
            ),
            'codigo_postal_ejecucion' => $this->xpathString(
                $cfs,
                'cac:ProcurementProject/cac:RealizedLocation/cac:Address/cbc:PostalZone'
            ),

            // Financiación UE
            'financiacion_ue' => $this->xpathString(
                $cfs,
                'cac:TenderingTerms/cbc:FundingProgramCode'
            ),

            // Criterios de adjudicación
            'criterios_adjudicacion' => $this->extractCriteriosAdjudicacion($cfs),

            // Fechas
            'fecha_limite' => $this->xpathDate(
                $cfs,
                'cac:TenderingProcess/cac:TenderSubmissionDeadlinePeriod/cbc:EndDate'
            ),
            'hora_limite' => $this->xpathString(
                $cfs,
                'cac:TenderingProcess/cac:TenderSubmissionDeadlinePeriod/cbc:EndTime'
            ),
            'fecha_publicacion' => $entryUpdated !== '' ? $this->parseDate($entryUpdated) : null,
        ];

        // Moneda — del atributo currencyID en importes
        $data['moneda'] = $this->extractCurrencyId($cfs);

        // Número de lote (ProcurementProjectLot)
        $data['numero_lote'] = $this->xpathInteger(
            $cfs,
            'cac:ProcurementProject/cbc:ProcurementProjectLotID'
        );

        // Adjudicación (TenderResult) — puede no existir si aún no hay adjudicación
        // TenderResult puede estar bajo cac-place-ext o cac según la versión del XML
        $tenderResults = $cfs->xpath('.//cac-place-ext:TenderResult');
        if (empty($tenderResults)) {
            $tenderResults = $cfs->xpath('.//cac:TenderResult');
        }

        if (! empty($tenderResults)) {
            $tr = $tenderResults[0];
            $this->registerNamespaces($tr);

            $data['resultado_codigo'] = $this->xpathString($tr, 'cbc:ResultCode');
            $data['nombre_adjudicatario'] = $this->xpathString(
                $tr,
                'cac:WinningParty/cac:PartyName/cbc:Name'
            );
            $data['nif_adjudicatario'] = $this->xpathString(
                $tr,
                'cac:WinningParty/cac:PartyIdentification/cbc:ID'
            );
            $data['pais_adjudicatario'] = $this->xpathString(
                $tr,
                'cac:WinningParty/cac:PostalAddress/cac:Country/cbc:IdentificationCode'
            );
            $data['importe_adjudicacion'] = $this->xpathDecimal(
                $tr,
                'cac:AwardedTenderedProject/cac:LegalMonetaryTotal/cbc:PayableAmount'
            );
            $data['importe_adjudicacion_con_iva'] = $this->xpathDecimal(
                $tr,
                'cac:AwardedTenderedProject/cac:LegalMonetaryTotal/cbc:TaxInclusiveAmount'
            );
            $data['num_ofertas'] = $this->xpathInteger($tr, 'cbc:ReceivedTenderQuantity');
            $data['es_pyme'] = $this->xpathBool($tr, 'cbc:SMEAwardedIndicator');
            $data['fecha_adjudicacion'] = $this->xpathDate($tr, 'cbc:AwardDate');
        }

        return $data;
    }

    /**
     * Busca el ContractFolderStatus dentro del entry.
     * Puede estar directamente en entry o dentro de entry/content o entry/summary.
     */
    public function getContractFolderStatus(SimpleXMLElement $entry): ?SimpleXMLElement
    {
        // Registrar namespaces en el entry
        $this->registerNamespaces($entry);

        // Directamente en el entry (formato más común en PLACSP)
        $result = $entry->xpath('.//cac-place-ext:ContractFolderStatus');
        if (! empty($result)) {
            return $result[0];
        }

        // Dentro de <content>
        if (isset($entry->content)) {
            $this->registerNamespaces($entry->content);
            $result = $entry->content->xpath('.//cac-place-ext:ContractFolderStatus');
            if (! empty($result)) {
                return $result[0];
            }
        }

        // Dentro de <summary>
        if (isset($entry->summary)) {
            $summaryType = (string) ($entry->summary['type'] ?? '');
            if ($summaryType === 'application/xml' || $summaryType === 'xml') {
                $this->registerNamespaces($entry->summary);
                $result = $entry->summary->xpath('.//cac-place-ext:ContractFolderStatus');
                if (! empty($result)) {
                    return $result[0];
                }
            }
        }

        return null;
    }

    private function registerNamespaces(SimpleXMLElement $xml): void
    {
        foreach (self::NAMESPACES as $prefix => $uri) {
            $xml->registerXPathNamespace($prefix, $uri);
        }
    }

    private function extractNifOrgano(SimpleXMLElement $cfs): ?string
    {
        $this->lastNifMatchedByName = false;

        $partyIds = $cfs->xpath(
            './/cac-place-ext:LocatedContractingParty/cac:Party/cac:PartyIdentification/cbc:ID'
        );

        if (empty($partyIds)) {
            return $this->tryMatchByOrgName($cfs);
        }

        // Intento 1: buscar NIF directamente
        foreach ($partyIds as $id) {
            $schemeName = (string) ($id['schemeName'] ?? '');
            if ($schemeName === 'NIF') {
                $nif = trim((string) $id);

                return $nif !== '' ? $nif : null;
            }
        }

        // Intento 2: buscar DIR3 y resolver NIF desde BD
        foreach ($partyIds as $id) {
            $schemeName = (string) ($id['schemeName'] ?? '');
            if ($schemeName === 'DIR3') {
                $dir3 = trim((string) $id);
                if ($dir3 !== '') {
                    $organismo = Organismo::where('dir3', $dir3)->first();
                    if ($organismo) {
                        return $organismo->nif;
                    }
                }
            }
        }

        // Intento 3: ID_OC_PLAT u otro identificador que parezca NIF/CIF español
        foreach ($partyIds as $id) {
            $value = trim((string) $id);
            if ($value !== '' && preg_match('/^[A-Z]\d{7}[A-Z0-9]$/', $value)) {
                return $value;
            }
        }

        // Intento 4: resolver NIF por nombre del órgano contratante
        return $this->tryMatchByOrgName($cfs);
    }

    /**
     * Intenta resolver el NIF buscando el nombre del órgano en la tabla organismos.
     * Solo aplica match exacto (case-insensitive) o por nombre base (sin cargo).
     */
    private function tryMatchByOrgName(SimpleXMLElement $cfs): ?string
    {
        $name = $this->xpathString(
            $cfs,
            'cac-place-ext:LocatedContractingParty/cac:Party/cac:PartyName/cbc:Name'
        );

        if ($name === null || strlen($name) < 5) {
            return null;
        }

        // Cache hit
        $cacheKey = mb_strtolower($name);
        if (array_key_exists($cacheKey, $this->nameNifCache)) {
            if ($this->nameNifCache[$cacheKey] !== null) {
                $this->lastNifMatchedByName = true;
            }

            return $this->nameNifCache[$cacheKey];
        }

        // Match exacto por nombre
        $organismo = Organismo::whereRaw('LOWER(nombre) = LOWER(?)', [$name])->first();
        if ($organismo) {
            $this->lastNifMatchedByName = true;
            $this->nameNifCache[$cacheKey] = $organismo->nif;

            return $organismo->nif;
        }

        // Match por nombre base (quitar cargo: -Director, -Presidente, -Pleno, etc.)
        $baseName = preg_replace(
            '/[-–,\s]+(Director|Presidente|Pleno|Junta|Consejo|Comisi[oó]n|Alcald[ií]a|Concejal|Secretar|Gerente|Vocal).*$/iu',
            '',
            $name
        );
        $baseName = trim($baseName ?? '');

        if (strlen($baseName) > 10 && $baseName !== $name) {
            $organismo = Organismo::whereRaw('LOWER(nombre) LIKE LOWER(?)', ['%'.$baseName.'%'])->first();
            if ($organismo) {
                $this->lastNifMatchedByName = true;
                $this->nameNifCache[$cacheKey] = $organismo->nif;

                return $organismo->nif;
            }
        }

        $this->nameNifCache[$cacheKey] = null;

        return null;
    }

    private function extractDir3(SimpleXMLElement $cfs): ?string
    {
        $partyIds = $cfs->xpath(
            './/cac-place-ext:LocatedContractingParty/cac:Party/cac:PartyIdentification/cbc:ID'
        );

        if (empty($partyIds)) {
            return null;
        }

        foreach ($partyIds as $id) {
            $schemeName = (string) ($id['schemeName'] ?? '');
            if ($schemeName === 'DIR3') {
                $dir3 = trim((string) $id);

                return $dir3 !== '' ? $dir3 : null;
            }
        }

        return null;
    }

    private function extractBuyerProfileUri(SimpleXMLElement $cfs): ?string
    {
        $value = $this->xpathString(
            $cfs,
            'cac-place-ext:LocatedContractingParty/cbc:BuyerProfileURIID'
        );

        if ($value !== null) {
            return html_entity_decode($value);
        }

        return null;
    }

    /**
     * @return array<int, array{tipo: string, descripcion: string, peso: int|null}>|null
     */
    private function extractCriteriosAdjudicacion(SimpleXMLElement $cfs): ?array
    {
        $nodes = $cfs->xpath('.//cac:TenderingTerms/cac:AwardingTerms/cac:AwardingCriteria');

        if (empty($nodes)) {
            return null;
        }

        $criterios = [];
        foreach ($nodes as $node) {
            $this->registerNamespaces($node);

            $tipo = $this->xpathString($node, 'cbc:AwardingCriteriaTypeCode');
            $descripcion = $this->xpathString($node, 'cbc:Description');
            $peso = $this->xpathInteger($node, 'cbc:WeightNumeric');

            if ($descripcion !== null || $tipo !== null) {
                $criterios[] = [
                    'tipo' => $tipo,
                    'descripcion' => $descripcion,
                    'peso' => $peso,
                ];
            }
        }

        return ! empty($criterios) ? $criterios : null;
    }

    private function extractIdPlataforma(SimpleXMLElement $cfs): ?string
    {
        $partyIds = $cfs->xpath(
            './/cac-place-ext:LocatedContractingParty/cac:Party/cac:PartyIdentification/cbc:ID'
        );

        if (empty($partyIds)) {
            return null;
        }

        foreach ($partyIds as $id) {
            $schemeName = (string) ($id['schemeName'] ?? '');
            if ($schemeName === 'ID_PLATAFORMA') {
                $value = trim((string) $id);

                return $value !== '' ? $value : null;
            }
        }

        return null;
    }

    private function extractCurrencyId(SimpleXMLElement $cfs): string
    {
        $nodes = $cfs->xpath(
            './/cac:ProcurementProject/cac:BudgetAmount/cbc:TotalAmount'
        );

        if (! empty($nodes)) {
            $currency = (string) ($nodes[0]['currencyID'] ?? '');
            if ($currency !== '') {
                return $currency;
            }
        }

        return 'EUR';
    }

    private function extractDuracion(SimpleXMLElement $cfs): ?string
    {
        $nodes = $cfs->xpath(
            './/cac:ProcurementProject/cac:PlannedPeriod/cbc:DurationMeasure'
        );

        if (empty($nodes)) {
            return null;
        }

        $node = $nodes[0];
        $value = trim((string) $node);
        $unitCode = (string) ($node['unitCode'] ?? '');

        if ($value === '') {
            return null;
        }

        if ($unitCode !== '') {
            return "{$value} {$unitCode}";
        }

        return $value;
    }

    /**
     * Construye un placsp_id estable a partir de la URL del entry.
     * Ejemplo: "https://contrataciondelestado.es/sindicacion/licitacionesPerfilContratante/18934459"
     * → "18934459"
     */
    private function buildPlacspId(string $entryId): string
    {
        // Extraer el ID numérico final de la URL
        $parts = explode('/', rtrim($entryId, '/'));
        $lastPart = end($parts);

        if (is_numeric($lastPart)) {
            return $lastPart;
        }

        // Fallback: usar hash de la URL completa
        return 'PLACSP-'.md5($entryId);
    }

    private function xpathString(SimpleXMLElement $xml, string $xpath): ?string
    {
        $result = $xml->xpath(".//{$xpath}");

        if (empty($result)) {
            return null;
        }

        $value = trim((string) $result[0]);

        return $value !== '' ? $value : null;
    }

    private function xpathDecimal(SimpleXMLElement $xml, string $xpath): ?float
    {
        $value = $this->xpathString($xml, $xpath);

        if ($value === null) {
            return null;
        }

        $cleaned = str_replace(',', '.', $value);

        return is_numeric($cleaned) ? (float) $cleaned : null;
    }

    private function xpathDate(SimpleXMLElement $xml, string $xpath): ?string
    {
        $value = $this->xpathString($xml, $xpath);

        if ($value === null) {
            return null;
        }

        return $this->parseDate($value);
    }

    private function xpathInteger(SimpleXMLElement $xml, string $xpath): ?int
    {
        $value = $this->xpathString($xml, $xpath);

        if ($value === null) {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    private function xpathBool(SimpleXMLElement $xml, string $xpath): ?bool
    {
        $value = $this->xpathString($xml, $xpath);

        if ($value === null) {
            return null;
        }

        return in_array(strtolower($value), ['true', '1', 'yes', 'si', 'sí'], true);
    }

    private function parseDate(string $value): ?string
    {
        try {
            $dt = new \DateTimeImmutable($value);

            return $dt->format('Y-m-d');
        } catch (\Exception) {
            return null;
        }
    }
}
