<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Placsp;

use App\Services\Placsp\PlacspEntryParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use SimpleXMLElement;
use Tests\TestCase;

class PlacspEntryParserTest extends TestCase
{
    use RefreshDatabase;

    private PlacspEntryParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new PlacspEntryParser;
    }

    public function test_parse_extracts_all_fields_from_complete_entry(): void
    {
        $entry = $this->loadFixtureEntry('entry_licitacion_clm.xml');

        $data = $this->parser->parse($entry);

        $this->assertNotNull($data);
        $this->assertEquals('99999001', $data['placsp_id']);
        $this->assertEquals('JCCM-2026/500', $data['expediente']);
        $this->assertEquals('ADJ', $data['estado']);
        $this->assertEquals('Junta de Comunidades de Castilla-La Mancha', $data['nombre_organo']);
        $this->assertEquals('S4500010I', $data['nif_organo']);
        $this->assertEquals('A08002911', $data['dir3']);
        $this->assertEquals('Servicio de desarrollo y mantenimiento de aplicaciones informáticas para la JCCM', $data['objeto']);
        $this->assertEquals('2', $data['tipo_contrato']);
        $this->assertEquals('1', $data['procedimiento']);
        $this->assertEquals(350000.0, $data['importe_licitacion']);
        $this->assertEquals(423500.0, $data['importe_licitacion_con_iva']);
        $this->assertEquals('36 MON', $data['duracion']);
        $this->assertEquals('72200000', $data['cpv']);
        $this->assertEquals('ES425', $data['nuts']);
        $this->assertEquals('Toledo', $data['lugar_ejecucion']);
        $this->assertEquals('2026-02-15', $data['fecha_limite']);
        $this->assertEquals('2026-03-01', $data['fecha_publicacion']);

        // Campos nuevos
        $this->assertEquals('a1b2c3d4-e5f6-7890-abcd-ef1234567890', $data['uuid_ted']);
        $this->assertEquals('72000000', $data['subtipo_contrato']);
        $this->assertEquals('1', $data['urgencia']);
        $this->assertEquals(420000.0, $data['importe_estimado']);
        $this->assertEquals('TOLEDO', $data['ciudad_ejecucion']);
        $this->assertEquals('45071', $data['codigo_postal_ejecucion']);
        $this->assertEquals('NO-EU', $data['financiacion_ue']);
        $this->assertEquals('14:00:00', $data['hora_limite']);
        $this->assertEquals('6', $data['codigo_actividad']);

        // Contacto órgano
        $this->assertEquals('Servicio de Contratación', $data['contacto_nombre']);
        $this->assertEquals('925267100', $data['contacto_telefono']);
        $this->assertEquals('contratacion@jccm.es', $data['contacto_email']);

        // Dirección órgano
        $this->assertEquals('Plaza Zocodover, 7', $data['direccion_organo']);
        $this->assertEquals('Toledo', $data['ciudad_organo']);
        $this->assertEquals('45071', $data['codigo_postal_organo']);

        // Criterios adjudicación
        $this->assertIsArray($data['criterios_adjudicacion']);
        $this->assertCount(2, $data['criterios_adjudicacion']);
        $this->assertEquals('Precio', $data['criterios_adjudicacion'][0]['descripcion']);
        $this->assertEquals(60, $data['criterios_adjudicacion'][0]['peso']);
        $this->assertEquals('Calidad técnica', $data['criterios_adjudicacion'][1]['descripcion']);
        $this->assertEquals(40, $data['criterios_adjudicacion'][1]['peso']);

        // Adjudicación
        $this->assertEquals('Tecnologías Avanzadas S.A.', $data['nombre_adjudicatario']);
        $this->assertEquals('A28001122', $data['nif_adjudicatario']);
        $this->assertEquals(320000.0, $data['importe_adjudicacion']);
        $this->assertEquals(387200.0, $data['importe_adjudicacion_con_iva']);
        $this->assertEquals(8, $data['num_ofertas']);
        $this->assertFalse($data['es_pyme']);
        $this->assertEquals('2026-02-28', $data['fecha_adjudicacion']);
    }

    public function test_parse_handles_entry_without_adjudicacion(): void
    {
        $xml = $this->loadFixtureFeed('atom_page_1.xml');
        $entry = $xml->entry[0]; // Entry Toledo sin adjudicación

        $data = $this->parser->parse($entry);

        $this->assertNotNull($data);
        $this->assertEquals('12345001', $data['placsp_id']);
        $this->assertEquals('EXP-2026/001', $data['expediente']);
        $this->assertEquals('PUB', $data['estado']);
        $this->assertEquals('P4500000A', $data['nif_organo']);
        $this->assertEquals('LA0003832', $data['dir3']);
        $this->assertEquals(150000.0, $data['importe_licitacion']);
        $this->assertEquals(181500.0, $data['importe_licitacion_con_iva']);

        // Sin adjudicación
        $this->assertArrayNotHasKey('nombre_adjudicatario', $data);
        $this->assertArrayNotHasKey('nif_adjudicatario', $data);
        $this->assertArrayNotHasKey('importe_adjudicacion', $data);
    }

    public function test_parse_extracts_adjudicacion_data(): void
    {
        $xml = $this->loadFixtureFeed('atom_page_1.xml');
        $entry = $xml->entry[1]; // Entry Ciudad Real con adjudicación

        $data = $this->parser->parse($entry);

        $this->assertNotNull($data);
        $this->assertEquals('Limpiezas Manchegas S.L.', $data['nombre_adjudicatario']);
        $this->assertEquals('B13999999', $data['nif_adjudicatario']);
        $this->assertEquals(72000.0, $data['importe_adjudicacion']);
        $this->assertEquals(87120.0, $data['importe_adjudicacion_con_iva']);
        $this->assertEquals(5, $data['num_ofertas']);
        $this->assertTrue($data['es_pyme']);
        $this->assertEquals('2026-01-15', $data['fecha_adjudicacion']);
    }

    public function test_parse_returns_null_without_nif_organo(): void
    {
        $entry = $this->buildEntryWithoutNif();
        $data = $this->parser->parse($entry);
        $this->assertNull($data);
    }

    public function test_parse_builds_placsp_id_from_entry_url(): void
    {
        $entry = $this->loadFixtureEntry('entry_licitacion_clm.xml');
        $data = $this->parser->parse($entry);

        $this->assertEquals('99999001', $data['placsp_id']);
    }

    public function test_parse_extracts_duracion_with_unit_code(): void
    {
        $entry = $this->loadFixtureEntry('entry_licitacion_clm.xml');
        $data = $this->parser->parse($entry);

        $this->assertEquals('36 MON', $data['duracion']);
    }

    public function test_parse_extracts_url_placsp_decoded(): void
    {
        $entry = $this->loadFixtureEntry('entry_licitacion_clm.xml');
        $data = $this->parser->parse($entry);

        $this->assertStringContainsString('deeplink:detalle_licitacion', $data['url_placsp']);
        $this->assertStringContainsString('idEvl=fullTest', $data['url_placsp']);
    }

    public function test_get_contract_folder_status_finds_in_entry(): void
    {
        $entry = $this->loadFixtureEntry('entry_licitacion_clm.xml');
        $cfs = $this->parser->getContractFolderStatus($entry);

        $this->assertNotNull($cfs);
    }

    private function loadFixtureEntry(string $filename): SimpleXMLElement
    {
        $path = base_path("tests/fixtures/placsp/{$filename}");
        $xml = simplexml_load_file($path);
        $this->assertNotFalse($xml);

        return $xml;
    }

    private function loadFixtureFeed(string $filename): SimpleXMLElement
    {
        $path = base_path("tests/fixtures/placsp/{$filename}");
        $xml = simplexml_load_file($path);
        $this->assertNotFalse($xml);

        return $xml;
    }

    private function buildEntryWithoutNif(): SimpleXMLElement
    {
        $xml = <<<'XML'
        <entry xmlns="http://www.w3.org/2005/Atom"
               xmlns:cbc="urn:dgpe:names:draft:codice:schema:xsd:CommonBasicComponents-2"
               xmlns:cac="urn:dgpe:names:draft:codice:schema:xsd:CommonAggregateComponents-2"
               xmlns:cac-place-ext="urn:dgpe:names:draft:codice-place-ext:schema:xsd:CommonAggregateComponents-2"
               xmlns:cbc-place-ext="urn:dgpe:names:draft:codice-place-ext:schema:xsd:CommonBasicComponents-2">
            <id>https://example.com/test/2</id>
            <link href="https://example.com/test/2"/>
            <title>Test sin NIF</title>
            <updated>2026-03-01T10:00:00+01:00</updated>
            <cac-place-ext:ContractFolderStatus>
                <cbc:ContractFolderID>TEST-002</cbc:ContractFolderID>
                <cbc-place-ext:ContractFolderStatusCode>PUB</cbc-place-ext:ContractFolderStatusCode>
                <cac-place-ext:LocatedContractingParty>
                    <cac:Party>
                        <cac:PartyName>
                            <cbc:Name>Organismo sin NIF</cbc:Name>
                        </cac:PartyName>
                    </cac:Party>
                </cac-place-ext:LocatedContractingParty>
                <cac:ProcurementProject>
                    <cbc:Name>Test sin NIF</cbc:Name>
                </cac:ProcurementProject>
            </cac-place-ext:ContractFolderStatus>
        </entry>
        XML;

        return new SimpleXMLElement($xml);
    }
}
