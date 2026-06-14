<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Regional;

use App\Services\Regional\AndaluciaParser;
use Tests\TestCase;

/**
 * Tests de caracterización para AndaluciaParser.
 *
 * El parser recibe un array $record (fila CSV de contratos menores de Andalucía)
 * y un int $year opcional. Devuelve array normalizado para ContratoImporter o null
 * si no hay ID/NUM_EXPEDIENTE.
 *
 * Limitación SQLite vs PostgreSQL: suite en SQLite :memory:; la rama PG-only
 * de scopeSearch (tsvector/FTS) no está cubierta aquí.
 */
class AndaluciaParserTest extends TestCase
{
    private AndaluciaParser $parser;

    /** Carga fixture CSV con cabecera en primera fila, separador coma. */
    private function loadFixture(): array
    {
        $path = __DIR__.'/../../../fixtures/regional/andalucia_sample.csv';
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $headers = str_getcsv($lines[0]);
        $records = [];
        for ($i = 1; $i < count($lines); $i++) {
            $values = str_getcsv($lines[$i]);
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
        $this->parser = new AndaluciaParser;
    }

    public function test_parse_fixture_devuelve_dos_registros_validos(): void
    {
        $records = $this->loadFixture();
        $parsed = array_filter(array_map(
            fn (array $r) => $this->parser->parse($r, 2023),
            $records
        ));

        // 2 válidos, 1 vacío sin ID
        $this->assertCount(2, $parsed);
    }

    public function test_parse_registro_servicios_campos_clave(): void
    {
        $record = [
            'ID_EXPEDIENTE' => 'AND-2023-001',
            'NUM_EXPEDIENTE' => 'EXP-AND-001',
            'ORGANO_CONTRATACION' => 'Agencia Andaluza del Agua',
            'TITULO' => 'Servicio de limpieza de instalaciones',
            'TIPO_CONTRATO' => 'Servicios',
            'IMPORTE_ADJUDICACION_SIN_IVA' => '8264.46',
            'IMPORTE_ADJUDICACION_CON_IVA' => '10000.00',
            'NIF_ADJUDICATARIO' => 'B41234567',
            'ADJUDICATARIO_DENOMINACION' => 'LIMPIEZAS SEVILLA S.L.',
            'FECHA_ADJUDICACION' => '2023-05-10',
            'FECHA_PUBLICACION' => '2023-05-15',
            'NUM_LICITADORES_PRESENTADOS' => '3',
            'LUGAR_EJECUCION_CODIGO' => 'ES61',
            'DURACION_CONTRATO' => '12',
            'DURACION_MEDIDA' => 'meses',
        ];

        $result = $this->parser->parse($record, 2023);

        $this->assertNotNull($result);
        $this->assertSame('ANDA-2023-AND-2023-001', $result['placsp_id']);
        $this->assertSame('Servicios', $result['tipo_contrato']);
        $this->assertSame('Contrato menor', $result['procedimiento']);
        $this->assertEquals(8264.46, $result['importe_adjudicacion']);
        $this->assertEquals(10000.00, $result['importe_adjudicacion_con_iva']);
        $this->assertSame('2023-05-10', $result['fecha_adjudicacion']);
        $this->assertSame('2023-05-15', $result['fecha_publicacion']);
        $this->assertSame('B41234567', $result['nif_adjudicatario']);
        $this->assertSame('LIMPIEZAS SEVILLA S.L.', $result['nombre_adjudicatario']);
        $this->assertSame('ES61', $result['nuts']);
        $this->assertSame(3, $result['num_ofertas']);
        $this->assertSame('12 meses', $result['duracion']);
        $this->assertTrue($result['es_menor']);
    }

    public function test_parse_registro_sin_expediente_devuelve_null(): void
    {
        $record = [
            'ID_EXPEDIENTE' => '',
            'NUM_EXPEDIENTE' => '',
            'ORGANO_CONTRATACION' => 'Algún organismo',
        ];

        $this->assertNull($this->parser->parse($record, 2023));
    }

    public function test_parse_importe_formato_europeo_con_punto_miles(): void
    {
        $record = [
            'ID_EXPEDIENTE' => 'AND-2023-IMP',
            'NUM_EXPEDIENTE' => '',
            'ORGANO_CONTRATACION' => 'Junta de Andalucía',
            'TITULO' => 'Prueba importe europeo',
            'TIPO_CONTRATO' => 'Suministros',
            'IMPORTE_ADJUDICACION_SIN_IVA' => '1.234,56',
            'IMPORTE_ADJUDICACION_CON_IVA' => '1.493,82',
            'NIF_ADJUDICATARIO' => 'A41000001',
            'ADJUDICATARIO_DENOMINACION' => 'EMPRESA PRUEBA SA',
            'FECHA_ADJUDICACION' => '',
            'FECHA_PUBLICACION' => '',
            'NUM_LICITADORES_PRESENTADOS' => '',
            'LUGAR_EJECUCION_CODIGO' => '',
            'DURACION_CONTRATO' => '',
            'DURACION_MEDIDA' => '',
        ];

        $result = $this->parser->parse($record, 2023);
        $this->assertNotNull($result);
        $this->assertEquals(1234.56, $result['importe_adjudicacion']);
        $this->assertEquals(1493.82, $result['importe_adjudicacion_con_iva']);
    }

    public function test_parse_sin_nif_organo_genera_nif_sintetico(): void
    {
        $record = [
            'ID_EXPEDIENTE' => 'AND-SYN-001',
            'NUM_EXPEDIENTE' => '',
            'ORGANO_CONTRATACION' => 'Consejería de Fomento',
            'TITULO' => 'Contrato sin NIF',
            'TIPO_CONTRATO' => 'Obras',
            'IMPORTE_ADJUDICACION_SIN_IVA' => '5000',
            'IMPORTE_ADJUDICACION_CON_IVA' => '6050',
            'NIF_ADJUDICATARIO' => 'A41000002',
            'ADJUDICATARIO_DENOMINACION' => 'CONSTRUCTORA SA',
            'FECHA_ADJUDICACION' => '',
            'FECHA_PUBLICACION' => '',
            'NUM_LICITADORES_PRESENTADOS' => '',
            'LUGAR_EJECUCION_CODIGO' => '',
            'DURACION_CONTRATO' => '',
            'DURACION_MEDIDA' => '',
        ];

        $result = $this->parser->parse($record, 2023);
        $this->assertNotNull($result);
        // El NIF sintético empieza con 'ANDA-'
        $this->assertStringStartsWith('ANDA-', $result['nif_organo']);
    }
}
