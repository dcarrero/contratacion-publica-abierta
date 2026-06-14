<?php

declare(strict_types=1);

namespace App\Services\Regional;

class CatalunyaParser
{
    /**
     * Traducciones catalán → castellano para tipos y procedimientos.
     *
     * @var array<string, string>
     */
    private array $traducciones;

    public function __construct()
    {
        $this->traducciones = config('contratacion.traducciones_catalan', []);
    }

    /**
     * Parsea un registro de la API Socrata de Catalunya al formato de ContratoImporter.
     *
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>|null
     */
    public function parse(array $record): ?array
    {
        $codiExpedient = $record['codi_expedient'] ?? null;
        $codiOrgan = $record['codi_dir3'] ?? $record['codi_organ'] ?? null;

        if (empty($codiExpedient) && empty($codiOrgan)) {
            return null;
        }

        // Generar placsp_id sintético
        $placspId = 'CAT-'.($codiOrgan ?? 'NOORG').'-'.($codiExpedient ?? 'NOEXP');

        // Resolver NIF del organismo: preferir NIF, fallback a DIR3
        $nifOrgano = $record['nif_organ'] ?? null;
        $dir3 = $record['codi_dir3'] ?? null;

        if (empty($nifOrgano) && ! empty($dir3)) {
            // Usar DIR3 como NIF sintético para organismos sin NIF real
            $nifOrgano = $dir3;
        }

        if (empty($nifOrgano)) {
            return null;
        }

        // Importes: pueden venir concatenados con || (multi-adjudicatario)
        // Campo real: import_adjudicacio_sense (sin _iva al final)
        $importeSinIva = $this->parseImporte($record['import_adjudicacio_sense'] ?? null);
        $importeConIva = $this->parseImporte($record['import_adjudicacio_amb_iva'] ?? null);

        // Importes de licitación (si existen)
        $importeLicitacionSinIva = $this->parseImporte($record['import_licitacio_sense'] ?? null);
        $importeLicitacionConIva = $this->parseImporte($record['import_licitacio_amb_iva'] ?? null);

        $data = [
            'placsp_id' => $placspId,
            'expediente' => $codiExpedient,
            'objeto' => $record['objecte_contracte'] ?? null,
            'nif_organo' => $nifOrgano,
            'nombre_organo' => $record['nom_organ'] ?? $record['denominacio_organ'] ?? null,
            'dir3' => $dir3,
            'tipo_contrato' => $this->traducir($record['tipus_contracte'] ?? null),
            'procedimiento' => $this->traducir($record['procediment'] ?? null),
            'estado' => $this->traducir($record['estat'] ?? null),
            'importe_licitacion' => $importeLicitacionSinIva,
            'importe_licitacion_con_iva' => $importeLicitacionConIva,
            'importe_adjudicacion' => $importeSinIva,
            'importe_adjudicacion_con_iva' => $importeConIva,
            'moneda' => 'EUR',
            'nif_adjudicatario' => $this->cleanNif($record['identificacio_adjudicatari'] ?? null),
            'nombre_adjudicatario' => $record['denominacio_adjudicatari'] ?? null,
            'cpv' => $record['codi_cpv'] ?? null,
            'nuts' => $record['codi_nuts'] ?? config('contratacion.regional.catalunya.default_nuts'),
            'num_ofertas' => $this->parseInteger($record['ofertes_rebudes'] ?? null),
            'fecha_adjudicacion' => $this->parseDate($record['data_adjudicacio_contracte'] ?? null),
            'fecha_publicacion' => $this->parseDate($record['data_publicacio_contracte'] ?? null),
            'duracion' => $record['termini_durada_contracte'] ?? null,
            'es_menor' => $this->isContratoMenor($record),
            'url_placsp' => $this->extractUrl($record['enllac_publicacio'] ?? null),
        ];

        return $data;
    }

    /**
     * Parsea importes que pueden venir concatenados con || (multi-adjudicatario).
     * Toma el primer valor válido.
     */
    private function parseImporte(?string $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        // Multi-adjudicatario: importes separados por ||
        if (str_contains($value, '||')) {
            $parts = explode('||', $value);
            $value = trim($parts[0]);
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        // Normalizar separadores decimales
        $value = str_replace(',', '.', $value);
        $float = (float) $value;

        return $float > 0 ? $float : null;
    }

    private function parseDate(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        // La API Socrata devuelve ISO 8601: 2023-01-15T00:00:00.000
        try {
            $date = new \DateTimeImmutable($value);

            return $date->format('Y-m-d');
        } catch (\Exception) {
            return null;
        }
    }

    private function parseInteger(?string $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        // Multi-valor con ||
        if (str_contains($value, '||')) {
            $parts = explode('||', $value);
            $value = trim($parts[0]);
        }

        return (int) $value ?: null;
    }

    private function cleanNif(?string $nif): ?string
    {
        if ($nif === null || $nif === '') {
            return null;
        }

        // Multi-adjudicatario: NIFs separados por ||
        if (str_contains($nif, '||')) {
            $parts = explode('||', $nif);
            $nif = trim($parts[0]);
        }

        $nif = mb_strtoupper(trim($nif));

        // Descartar valores que no parecen NIF/CIF válidos
        if (mb_strlen($nif) < 5) {
            return null;
        }

        return $nif;
    }

    private function extractUrl(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        // La API devuelve {'url': 'https://...'} como objeto o string JSON
        if (is_array($value) && isset($value['url'])) {
            return $value['url'];
        }

        if (is_string($value)) {
            if (str_starts_with($value, 'http')) {
                return $value;
            }

            $decoded = json_decode($value, true);
            if (isset($decoded['url'])) {
                return $decoded['url'];
            }
        }

        return null;
    }

    private function traducir(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->traducciones[$value] ?? $value;
    }

    private function isContratoMenor(array $record): bool
    {
        $procedimiento = $record['procediment'] ?? '';
        $tipo = $record['tipus_contracte'] ?? '';

        return mb_stripos($procedimiento, 'menor') !== false
            || mb_stripos($procedimiento, 'contracte menor') !== false
            || mb_stripos($tipo, 'menor') !== false;
    }
}
