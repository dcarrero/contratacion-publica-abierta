<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Regional;

use App\Services\Regional\AsturiasParser;
use Tests\TestCase;

/**
 * Tests de caracterización para AsturiasParser.
 *
 * El parser recibe un array $record (columnas del CSV con delimitador §)
 * y devuelve un array normalizado listo para ContratoImporter, o null si
 * el registro no tiene número de inscripción.
 *
 * Limitación SQLite vs PostgreSQL: estos tests se ejecutan en SQLite :memory:.
 * Los scopes scopeSearch() con FTS tsvector (PG-only) no están cubiertos aquí.
 */
class AsturiasParserTest extends TestCase
{
    private AsturiasParser $parser;

    /** Fixture CSV con delimitador § cargado como array de records. */
    private function loadFixture(): array
    {
        $path = __DIR__.'/../../../fixtures/regional/asturias_sample.csv';
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        // § es multi-byte, str_getcsv no lo acepta como separador — usar explode
        $headers = explode('§', $lines[0]);
        $records = [];
        for ($i = 1; $i < count($lines); $i++) {
            $values = explode('§', $lines[$i]);
            // Pad values to match header count
            while (count($values) < count($headers)) {
                $values[] = '';
            }
            $records[] = array_combine($headers, $values);
        }

        return $records;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new AsturiasParser;
    }

    public function test_parse_fixture_devuelve_dos_registros_validos(): void
    {
        $records = $this->loadFixture();

        $parsed = array_filter(array_map([$this->parser, 'parse'], $records));

        // El fixture tiene 2 registros válidos y 1 vacío (sin inscripción)
        $this->assertCount(2, $parsed);
    }

    public function test_parse_registro_suministros_campos_clave(): void
    {
        $record = [
            'Nº INSCRIPCION' => '2023/001',
            'AÑO' => '2023',
            'ENTE CONTRATANTE' => 'Servicio de Salud del Principado de Asturias',
            'ORGANO CONTRATANTE' => 'Gerencia de Atención Primaria',
            'OBJETO' => 'Suministro de material fungible sanitario',
            'CARACTERISTICAS CONTRATO' => 'SUMINISTROS',
            'PROC. ADJUDICACION' => 'ABIERTO',
            'IMP. ADJ. (CON IVA)' => '1.210,00',
            'IMP. ADJ. IMPUESTO' => '210,00',
            'IVA' => '21',
            'Nº EXPEDIENTE ORGANO' => 'EXP-2023-001',
            'CODIGO CPV' => '33140000',
            'ESTADO CONTRATO' => 'ADJUDICADO',
            'F. ADJ.' => '15/03/2023',
            'F. FORMALIZACION' => '20/03/2023',
            'F. DE ALTA' => '01/03/2023',
            'NIF/CIF CONTRATISTA' => 'B33123456',
            'RAZON SOCIAL CONTRATISTA' => 'SUMINISTROS MÉDICOS ASTURIAS, S.L.',
            'ID. PLACE' => '',
        ];

        $result = $this->parser->parse($record);

        $this->assertNotNull($result);
        $this->assertSame('AST-2023/001', $result['placsp_id']);
        $this->assertSame('Suministros', $result['tipo_contrato']);
        $this->assertSame('Abierto', $result['procedimiento']);
        // Importe sin IVA: 1210.00 - 210.00 = 1000.00
        $this->assertEquals(1000.00, $result['importe_adjudicacion']);
        // Importe con IVA almacenado en importe_adjudicacion_con_iva
        $this->assertEquals(1210.00, $result['importe_adjudicacion_con_iva']);
        $this->assertSame('2023-03-01', $result['fecha_publicacion']);
        $this->assertSame('2023-03-15', $result['fecha_adjudicacion']);
        $this->assertSame('B33123456', $result['nif_adjudicatario']);
        $this->assertSame('SUMINISTROS MÉDICOS ASTURIAS, S.L.', $result['nombre_adjudicatario']);
        $this->assertSame('33140000', $result['cpv']);
        // NUTS viene de config; no debe ser null
        $this->assertNotNull($result['nuts']);
    }

    public function test_parse_registro_sin_inscripcion_devuelve_null(): void
    {
        $record = [
            'Nº INSCRIPCION' => '',
            'AÑO' => '',
            'ENTE CONTRATANTE' => '',
            'ORGANO CONTRATANTE' => '',
            'OBJETO' => '',
        ];

        $this->assertNull($this->parser->parse($record));
    }

    public function test_parse_importe_formato_europeo_1234_56(): void
    {
        // Verifica que "1.234,56" se parsea correctamente a 1234.56
        $record = [
            'Nº INSCRIPCION' => '2023/IMP',
            'AÑO' => '2023',
            'ENTE CONTRATANTE' => '',
            'ORGANO CONTRATANTE' => 'Organismo de Prueba',
            'OBJETO' => 'Prueba importe',
            'CARACTERISTICAS CONTRATO' => 'SERVICIOS',
            'PROC. ADJUDICACION' => 'NEGOCIADO',
            'IMP. ADJ. (CON IVA)' => '1.234,56',
            'IMP. ADJ. IMPUESTO' => '214,56',
            'IVA' => '21',
            'Nº EXPEDIENTE ORGANO' => '',
            'CODIGO CPV' => '',
            'ESTADO CONTRATO' => '',
            'F. ADJ.' => '',
            'F. FORMALIZACION' => '',
            'F. DE ALTA' => '',
            'NIF/CIF CONTRATISTA' => 'B33000001',
            'RAZON SOCIAL CONTRATISTA' => 'PROVEEDOR PRUEBA',
            'ID. PLACE' => '',
        ];

        $result = $this->parser->parse($record);
        $this->assertNotNull($result);
        // 1234.56 - 214.56 = 1020.00
        $this->assertEquals(1020.00, $result['importe_adjudicacion']);
        $this->assertEquals(1234.56, $result['importe_adjudicacion_con_iva']);
    }

    public function test_parse_formato_2019_contratistas_extrae_nif_y_nombre(): void
    {
        // Formato 2019-2021: col "CONTRATISTAS" = "NIF - NOMBRE"
        $record = [
            'Nº INSCRIPCION' => '2019/001',
            'AÑO' => '2019',
            'ENTE CONTRATANTE' => '',
            'ORGANO CONTRATANTE' => 'Organismo Antiguo',
            'OBJETO' => 'Contrato antiguo',
            'CARACTERISTICAS CONTRATO' => 'OBRAS',
            'PROC. ADJUDICACION' => 'ABIERTO',
            'IMP. ADJ. (CON IVA)' => '5.000,00',
            'IMP. ADJ. IMPUESTO' => '0',
            'IVA' => '0',
            'Nº EXPEDIENTE ORGANO' => '',
            'CODIGO CPV' => '',
            'ESTADO CONTRATO' => '',
            'F. ADJ.' => '',
            'F. FORMALIZACION' => '',
            'F. DE ALTA' => '',
            'CONTRATISTAS' => 'B29060381 - ZIMMER BIOMET SPAIN, S.L.U.',
        ];

        $result = $this->parser->parse($record);
        $this->assertNotNull($result);
        $this->assertSame('B29060381', $result['nif_adjudicatario']);
        $this->assertSame('ZIMMER BIOMET SPAIN, S.L.U.', $result['nombre_adjudicatario']);
    }
}
