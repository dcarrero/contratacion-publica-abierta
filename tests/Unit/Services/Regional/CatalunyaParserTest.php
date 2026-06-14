<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Regional;

use App\Services\Regional\CatalunyaParser;
use Tests\TestCase;

/**
 * Tests de caracterización para CatalunyaParser.
 *
 * El parser recibe un array $record (registro JSON de la API Socrata de Catalunya)
 * y devuelve un array normalizado para ContratoImporter, o null si no hay
 * codi_expedient ni codi_organ (o nif_organ vacío).
 *
 * El constructor carga traducciones catalán→castellano desde
 * config('contratacion.traducciones_catalan').
 *
 * Limitación SQLite vs PostgreSQL: suite en SQLite :memory:; la rama PG-only
 * de scopeSearch (tsvector/FTS) no está cubierta aquí.
 */
class CatalunyaParserTest extends TestCase
{
    private CatalunyaParser $parser;

    /** Carga fixture JSON como array de records. */
    private function loadFixture(): array
    {
        $path = __DIR__.'/../../../fixtures/regional/catalunya_sample.json';

        return json_decode((string) file_get_contents($path), true);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new CatalunyaParser;
    }

    public function test_parse_fixture_devuelve_dos_registros_validos(): void
    {
        $records = $this->loadFixture();
        $parsed = array_filter(array_map([$this->parser, 'parse'], $records));

        // 2 válidos, 1 vacío sin codi_expedient/codi_dir3/nif_organ
        $this->assertCount(2, $parsed);
    }

    public function test_parse_registro_servicios_campos_clave(): void
    {
        $record = [
            'codi_expedient' => '2023/CAT/001',
            'codi_dir3' => 'A09018933',
            'nif_organ' => 'Q0801175A',
            'nom_organ' => "Departament d'Educació",
            'objecte_contracte' => 'Servei de neteja d\'edificis educatius',
            'tipus_contracte' => 'Serveis',
            'procediment' => 'Obert',
            'estat' => 'Adjudicat',
            'import_adjudicacio_sense' => '45000.00',
            'import_adjudicacio_amb_iva' => '54450.00',
            'import_licitacio_sense' => '50000.00',
            'import_licitacio_amb_iva' => '60500.00',
            'identificacio_adjudicatari' => 'B08123456',
            'denominacio_adjudicatari' => 'NETEJA SERVEIS BARCELONA, S.L.',
            'codi_cpv' => '90910000',
            'codi_nuts' => 'ES511',
            'ofertes_rebudes' => '4',
            'data_adjudicacio_contracte' => '2023-04-15T00:00:00.000',
            'data_publicacio_contracte' => '2023-04-20T00:00:00.000',
            'termini_durada_contracte' => '24 mesos',
            'enllac_publicacio' => 'https://contractaciopublica.cat/expedient/2023CAT001',
        ];

        $result = $this->parser->parse($record);

        $this->assertNotNull($result);
        $this->assertSame('CAT-A09018933-2023/CAT/001', $result['placsp_id']);
        $this->assertSame('Q0801175A', $result['nif_organo']);
        // Traducción catalán → castellano
        $this->assertSame('Servicios', $result['tipo_contrato']);
        $this->assertSame('Abierto', $result['procedimiento']);
        $this->assertEquals(45000.00, $result['importe_adjudicacion']);
        $this->assertEquals(54450.00, $result['importe_adjudicacion_con_iva']);
        $this->assertEquals(50000.00, $result['importe_licitacion']);
        $this->assertEquals(60500.00, $result['importe_licitacion_con_iva']);
        $this->assertSame('2023-04-15', $result['fecha_adjudicacion']);
        $this->assertSame('2023-04-20', $result['fecha_publicacion']);
        $this->assertSame('B08123456', $result['nif_adjudicatario']);
        $this->assertSame('90910000', $result['cpv']);
        $this->assertSame('ES511', $result['nuts']);
        $this->assertSame(4, $result['num_ofertas']);
        $this->assertFalse($result['es_menor']);
        $this->assertSame(
            'https://contractaciopublica.cat/expedient/2023CAT001',
            $result['url_placsp']
        );
    }

    public function test_parse_registro_contracte_menor_detecta_es_menor_true(): void
    {
        $record = [
            'codi_expedient' => '2023/CAT/002',
            'codi_dir3' => 'A09018934',
            'nif_organ' => 'Q0801176B',
            'nom_organ' => 'Departament de Salut',
            'objecte_contracte' => 'Subministrament de material sanitari',
            'tipus_contracte' => 'Subministraments',
            'procediment' => 'Contracte menor',
            'estat' => 'Formalitzat',
            'import_adjudicacio_sense' => '2500.00',
            'import_adjudicacio_amb_iva' => '3025.00',
            'import_licitacio_sense' => null,
            'import_licitacio_amb_iva' => null,
            'identificacio_adjudicatari' => 'B08654321',
            'denominacio_adjudicatari' => 'MATERIAL MÈDIC CAT, S.L.',
            'codi_cpv' => '33140000',
            'codi_nuts' => 'ES511',
            'ofertes_rebudes' => '1',
            'data_adjudicacio_contracte' => '2023-03-10T00:00:00.000',
            'data_publicacio_contracte' => '2023-03-12T00:00:00.000',
            'termini_durada_contracte' => '3 mesos',
            'enllac_publicacio' => null,
        ];

        $result = $this->parser->parse($record);

        $this->assertNotNull($result);
        $this->assertTrue($result['es_menor']);
        $this->assertSame('Suministros', $result['tipo_contrato']);
        $this->assertSame('Contrato menor', $result['procedimiento']);
    }

    public function test_parse_registro_sin_nif_organ_usa_dir3_sintetico(): void
    {
        $record = [
            'codi_expedient' => '2023/CAT/003',
            'codi_dir3' => 'A09018999',
            'nif_organ' => null,
            'nom_organ' => 'Organisme sense NIF',
            'objecte_contracte' => 'Contracte de prova',
            'tipus_contracte' => 'Serveis',
            'procediment' => 'Obert',
            'estat' => 'Adjudicat',
            'import_adjudicacio_sense' => '10000.00',
            'import_adjudicacio_amb_iva' => '12100.00',
            'import_licitacio_sense' => null,
            'import_licitacio_amb_iva' => null,
            'identificacio_adjudicatari' => 'B08000001',
            'denominacio_adjudicatari' => 'PROVEÏDOR CAT SL',
            'codi_cpv' => null,
            'codi_nuts' => 'ES512',
            'ofertes_rebudes' => '2',
            'data_adjudicacio_contracte' => '2023-07-01T00:00:00.000',
            'data_publicacio_contracte' => '2023-07-05T00:00:00.000',
            'termini_durada_contracte' => null,
            'enllac_publicacio' => null,
        ];

        $result = $this->parser->parse($record);
        $this->assertNotNull($result);
        // Si no hay nif_organ, se usa codi_dir3 como fallback
        $this->assertSame('A09018999', $result['nif_organo']);
    }

    public function test_parse_registro_vacio_devuelve_null(): void
    {
        $record = [
            'codi_expedient' => null,
            'codi_dir3' => null,
            'nif_organ' => null,
        ];

        $this->assertNull($this->parser->parse($record));
    }

    public function test_parse_importe_multi_adjudicatario_toma_primer_valor(): void
    {
        // La API puede devolver importes separados por || para multi-adjudicatario
        $record = [
            'codi_expedient' => '2023/CAT/MULTI',
            'codi_dir3' => 'A09018000',
            'nif_organ' => 'Q0801100Z',
            'nom_organ' => 'Organisme Multi',
            'objecte_contracte' => 'Contracte multi-adjudicatari',
            'tipus_contracte' => 'Serveis',
            'procediment' => 'Obert',
            'estat' => 'Adjudicat',
            'import_adjudicacio_sense' => '10000.00||5000.00',
            'import_adjudicacio_amb_iva' => '12100.00||6050.00',
            'import_licitacio_sense' => null,
            'import_licitacio_amb_iva' => null,
            'identificacio_adjudicatari' => 'B08000011||B08000022',
            'denominacio_adjudicatari' => 'EMPRESA A||EMPRESA B',
            'codi_cpv' => null,
            'codi_nuts' => 'ES511',
            'ofertes_rebudes' => '3',
            'data_adjudicacio_contracte' => '2023-08-01T00:00:00.000',
            'data_publicacio_contracte' => '2023-08-05T00:00:00.000',
            'termini_durada_contracte' => null,
            'enllac_publicacio' => null,
        ];

        $result = $this->parser->parse($record);
        $this->assertNotNull($result);
        // Debe tomar el primer valor del multi-value
        $this->assertEquals(10000.00, $result['importe_adjudicacion']);
        $this->assertSame('B08000011', $result['nif_adjudicatario']);
    }
}
