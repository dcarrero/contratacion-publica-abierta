<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Regional;

use App\Services\Regional\MurciaParser;
use Tests\TestCase;

/**
 * Tests de caracterización para MurciaParser.
 *
 * El parser maneja dos series CSV de la CARM (Comunidad Autónoma de la Región de Murcia):
 *
 * Serie A — contratos mayores (excl. menores):
 *   URL: https://datosabiertos.carm.es/odata/transparencia/contratosOD{AÑO}.csv
 *   Separador: coma. Encoding: UTF-8.
 *   Campo NIF: adjudicatariocodigo (ej: "G30123456")
 *   Importe: importadjudicacion (sin IVA, con punto decimal)
 *   Fecha: fechaformalizacion (DD/MM/YYYY)
 *
 * Serie B — contratos menores:
 *   URL: https://datosabiertos.carm.es/odata/Hacienda/CONTRA_ContratosMenores_{AÑO}.csv
 *   Separador: coma. Encoding: UTF-8.
 *   Campo NIF: ADJUDICATARIOCODIGO (ej: "B30111222")
 *   Importe: IMPORTECONTABPAGO (importe pagado, con punto decimal)
 *   Fecha: FECHACONTABPAGO (DD/MM/YYYY)
 *
 * El NUTS no está en los CSVs — el parser lo infiere como ES62 (Región de Murcia).
 * El NIF del organismo contratante tampoco está — se genera un NIF sintético.
 */
class MurciaParserTest extends TestCase
{
    private MurciaParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new MurciaParser;
    }

    // =========================================================================
    // Helpers para cargar fixtures
    // =========================================================================

    /**
     * Carga el fixture CSV de contratos mayores.
     *
     * @return array<int, array<string, string>>
     */
    private function loadFixtureMayor(): array
    {
        $path = __DIR__.'/../../../fixtures/regional/murcia_mayor_sample.csv';

        return $this->parseCsv($path);
    }

    /**
     * Carga el fixture CSV de contratos menores.
     *
     * @return array<int, array<string, string>>
     */
    private function loadFixtureMenor(): array
    {
        $path = __DIR__.'/../../../fixtures/regional/murcia_menor_sample.csv';

        return $this->parseCsv($path);
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function parseCsv(string $path): array
    {
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

    // =========================================================================
    // Fixture — contratos mayores
    // =========================================================================

    public function test_parse_mayor_fixture_devuelve_tres_registros(): void
    {
        $records = $this->loadFixtureMayor();

        $parsed = array_values(array_filter(
            array_map([$this->parser, 'parseMayor'], $records)
        ));

        // Fixture tiene 3 filas; todas deben parsear (la de obras no tiene NIF, pero sí campos mínimos)
        $this->assertCount(3, $parsed);
    }

    public function test_parse_mayor_registro_servicios_campos_clave(): void
    {
        $record = [
            'ejercicio' => '2023',
            'codinscripcion' => '2023/000123',
            'tipocontrato' => 'SERVICIOS',
            'procedimiento' => 'ABIERTO',
            'objeto' => 'Servicio de transporte escolar zona I-9',
            'importlicitacion' => '90000',
            'importadjudicacion' => '84607.44',
            'adjudicatariodescripcion' => 'UTE TRAVELPYM',
            'adjudicatariocodigo' => 'G30123456',
            'fechaformalizacion' => '30/12/2023',
            'duracion' => '12 meses',
            'organo' => 'Consejeria de Educacion y Cultura',
            'cpvcodigo' => '60112000',
            'cpvdescripcion' => 'Servicio de transporte escolar',
            'nummodificaciones' => '0',
            'codigoOrgano' => 'A19001',
            'fechaInicio' => '01/01/2023',
        ];

        $result = $this->parser->parseMayor($record);

        $this->assertNotNull($result);
        $this->assertSame('MUR-2023-2023/000123', $result['placsp_id']);
        $this->assertSame('Servicios', $result['tipo_contrato']);
        $this->assertSame('Abierto', $result['procedimiento']);
        $this->assertEquals(84607.44, $result['importe_adjudicacion']);
        $this->assertEquals(90000.0, $result['importe_licitacion']);
        $this->assertSame('2023-12-30', $result['fecha_formalizacion']);
        $this->assertSame('G30123456', $result['nif_adjudicatario']);
        $this->assertSame('UTE TRAVELPYM', $result['nombre_adjudicatario']);
        $this->assertSame('60112000', $result['cpv']);
        $this->assertSame('ES62', $result['nuts']);
        $this->assertFalse($result['es_menor']);
        // NIF organismo debe ser sintético (empieza con MUR-)
        $this->assertStringStartsWith('MUR-', $result['nif_organo']);
    }

    public function test_parse_mayor_registro_sin_nif_adjudicatario_se_acepta(): void
    {
        // El registro de obras del fixture no tiene NIF — debe parsear igualmente
        $record = [
            'ejercicio' => '2023',
            'codinscripcion' => '2023/000789',
            'tipocontrato' => 'OBRAS',
            'procedimiento' => 'ABIERTO SIMPLIFICADO',
            'objeto' => 'Obras de rehabilitacion en edificio administrativo',
            'importlicitacion' => '200000',
            'importadjudicacion' => '185000.00',
            'adjudicatariodescripcion' => '',
            'adjudicatariocodigo' => '',
            'fechaformalizacion' => '',
            'duracion' => '24 meses',
            'organo' => 'Consejeria de Hacienda',
            'cpvcodigo' => '45000000',
            'cpvdescripcion' => 'Trabajos de construccion',
            'nummodificaciones' => '1',
            'codigoOrgano' => 'A21001',
            'fechaInicio' => '15/06/2023',
        ];

        $result = $this->parser->parseMayor($record);

        $this->assertNotNull($result);
        $this->assertNull($result['nif_adjudicatario']);
        $this->assertSame('Obras', $result['tipo_contrato']);
        $this->assertEquals(185000.0, $result['importe_adjudicacion']);
    }

    public function test_parse_mayor_sin_codinscripcion_devuelve_null(): void
    {
        $record = [
            'ejercicio' => '2023',
            'codinscripcion' => '',
            'organo' => 'Consejeria de Prueba',
        ];

        $this->assertNull($this->parser->parseMayor($record));
    }

    public function test_parse_mayor_nif_organo_consistente_mismo_codigo(): void
    {
        // Dos contratos del mismo organismo deben generar el mismo NIF sintético
        $base = [
            'ejercicio' => '2023',
            'codinscripcion' => '2023/AAA',
            'tipocontrato' => 'SERVICIOS',
            'procedimiento' => '',
            'objeto' => 'Contrato A',
            'importlicitacion' => '1000',
            'importadjudicacion' => '900',
            'adjudicatariodescripcion' => 'Empresa X',
            'adjudicatariocodigo' => 'A11111111',
            'fechaformalizacion' => '',
            'duracion' => '',
            'organo' => 'Consejeria de Hacienda',
            'cpvcodigo' => '',
            'cpvdescripcion' => '',
            'nummodificaciones' => '0',
            'codigoOrgano' => 'A21001',
            'fechaInicio' => '',
        ];

        $r1 = $this->parser->parseMayor($base);

        $base['codinscripcion'] = '2023/BBB';
        $r2 = $this->parser->parseMayor($base);

        $this->assertNotNull($r1);
        $this->assertNotNull($r2);
        $this->assertSame($r1['nif_organo'], $r2['nif_organo']);
    }

    // =========================================================================
    // Fixture — contratos menores
    // =========================================================================

    public function test_parse_menor_fixture_devuelve_tres_registros(): void
    {
        $records = $this->loadFixtureMenor();

        $parsed = array_values(array_filter(
            array_map([$this->parser, 'parseMenor'], $records)
        ));

        $this->assertCount(3, $parsed);
    }

    public function test_parse_menor_registro_suministros_campos_clave(): void
    {
        $record = [
            'CODEXPEDIENTE' => '2024/034602',
            'OBJETO_CONTRATO_MENOR' => 'Suministro de agua para Biblioteca Regional durante 2024',
            'UNIDADCODIGO' => '19',
            'UNIDADDESCRIPCION' => 'Consejeria de Turismo Cultura Juventud y Deportes',
            'CENTROCODIGO' => '19001',
            'CENTRODESCRIPCION' => 'Biblioteca Regional de Murcia',
            'TIPOCONTRATO' => 'SUMINISTROS',
            'CPVCODIGO' => '65100000',
            'CPVDESCRIPCION' => 'Distribucion de agua',
            'ADJUDICATARIOCODIGO' => 'B30111222',
            'ADJUDICATARIODESCRIPCION' => 'EMPRESA MUNICIPAL AGUAS SA',
            'FECHACONTABPAGO' => '15/01/2024',
            'IMPORTECONTABPAGO' => '33.50',
            'EJERCICIONUM' => '2024',
            'TRIMESTRENUM' => '4',
        ];

        $result = $this->parser->parseMenor($record);

        $this->assertNotNull($result);
        $this->assertSame('MUR-MEN-2024-2024/034602-T4', $result['placsp_id']);
        $this->assertSame('Suministros', $result['tipo_contrato']);
        $this->assertSame('Contrato menor', $result['procedimiento']);
        $this->assertEquals(33.50, $result['importe_adjudicacion']);
        $this->assertSame('2024-01-15', $result['fecha_adjudicacion']);
        $this->assertSame('B30111222', $result['nif_adjudicatario']);
        $this->assertSame('EMPRESA MUNICIPAL AGUAS SA', $result['nombre_adjudicatario']);
        $this->assertSame('65100000', $result['cpv']);
        $this->assertSame('ES62', $result['nuts']);
        $this->assertTrue($result['es_menor']);
        // NIF organismo sintético basado en código de unidad
        $this->assertStringStartsWith('MUR-', $result['nif_organo']);
    }

    public function test_parse_menor_objeto_y_organo_correctos(): void
    {
        $record = [
            'CODEXPEDIENTE' => '2024/039926',
            'OBJETO_CONTRATO_MENOR' => 'Analisis de nutrientes en suelo para Centro Agricola Torre Pacheco',
            'UNIDADCODIGO' => '17',
            'UNIDADDESCRIPCION' => 'Consejeria de Agua Agricultura y Medio Ambiente',
            'CENTROCODIGO' => '17002',
            'CENTRODESCRIPCION' => 'Centro de Capacitacion y Experimentacion Agricola',
            'TIPOCONTRATO' => 'SERVICIOS',
            'CPVCODIGO' => '73111000',
            'CPVDESCRIPCION' => 'Servicios de laboratorio de investigacion',
            'ADJUDICATARIOCODIGO' => 'B30654321',
            'ADJUDICATARIODESCRIPCION' => 'LABORATORIO KUDAM SL',
            'FECHACONTABPAGO' => '30/10/2024',
            'IMPORTECONTABPAGO' => '181.50',
            'EJERCICIONUM' => '2024',
            'TRIMESTRENUM' => '3',
        ];

        $result = $this->parser->parseMenor($record);

        $this->assertNotNull($result);
        $this->assertSame(
            'Analisis de nutrientes en suelo para Centro Agricola Torre Pacheco',
            $result['objeto']
        );
        $this->assertSame(
            'Consejeria de Agua Agricultura y Medio Ambiente',
            $result['nombre_organo']
        );
        $this->assertEquals(181.50, $result['importe_adjudicacion']);
        $this->assertSame('B30654321', $result['nif_adjudicatario']);
        $this->assertSame('LABORATORIO KUDAM SL', $result['nombre_adjudicatario']);
    }

    public function test_parse_menor_sin_expediente_devuelve_null(): void
    {
        $record = [
            'CODEXPEDIENTE' => '',
            'OBJETO_CONTRATO_MENOR' => 'Sin expediente',
            'UNIDADCODIGO' => '1',
            'UNIDADDESCRIPCION' => 'Organismo',
        ];

        $this->assertNull($this->parser->parseMenor($record));
    }

    public function test_parse_menor_importe_formato_europeo(): void
    {
        // Verificar que "1.210,50" (formato europeo) se parsea correctamente
        $record = [
            'CODEXPEDIENTE' => '2024/99999',
            'OBJETO_CONTRATO_MENOR' => 'Contrato con importe europeo',
            'UNIDADCODIGO' => '5',
            'UNIDADDESCRIPCION' => 'Organismo de prueba',
            'CENTROCODIGO' => '',
            'CENTRODESCRIPCION' => '',
            'TIPOCONTRATO' => 'SERVICIOS',
            'CPVCODIGO' => '',
            'CPVDESCRIPCION' => '',
            'ADJUDICATARIOCODIGO' => 'A30000001',
            'ADJUDICATARIODESCRIPCION' => 'Empresa Prueba',
            'FECHACONTABPAGO' => '01/06/2024',
            'IMPORTECONTABPAGO' => '1.210,50',
            'EJERCICIONUM' => '2024',
            'TRIMESTRENUM' => '2',
        ];

        $result = $this->parser->parseMenor($record);

        $this->assertNotNull($result);
        $this->assertEquals(1210.50, $result['importe_adjudicacion']);
    }

    public function test_parse_menor_fecha_formato_dd_mm_yyyy(): void
    {
        $record = [
            'CODEXPEDIENTE' => '2024/FECHA',
            'OBJETO_CONTRATO_MENOR' => 'Test fecha',
            'UNIDADCODIGO' => '1',
            'UNIDADDESCRIPCION' => 'Organismo',
            'CENTROCODIGO' => '',
            'CENTRODESCRIPCION' => '',
            'TIPOCONTRATO' => 'SERVICIOS',
            'CPVCODIGO' => '',
            'CPVDESCRIPCION' => '',
            'ADJUDICATARIOCODIGO' => 'B12345678',
            'ADJUDICATARIODESCRIPCION' => 'Empresa',
            'FECHACONTABPAGO' => '05/03/2024',
            'IMPORTECONTABPAGO' => '500',
            'EJERCICIONUM' => '2024',
            'TRIMESTRENUM' => '1',
        ];

        $result = $this->parser->parseMenor($record);

        $this->assertNotNull($result);
        $this->assertSame('2024-03-05', $result['fecha_adjudicacion']);
    }

    // =========================================================================
    // cleanNif — UTEs y valores basura
    // =========================================================================

    public function test_parse_mayor_nif_ute_toma_primer_miembro(): void
    {
        // NIF UTE: varios NIF concatenados con "/" → se toma el primero (miembro principal)
        $record = [
            'ejercicio' => '2023',
            'codinscripcion' => '2023/UTE001',
            'tipocontrato' => 'OBRAS',
            'procedimiento' => 'ABIERTO',
            'objeto' => 'Obras UTE ejemplo',
            'importlicitacion' => '500000',
            'importadjudicacion' => '480000',
            'adjudicatariodescripcion' => 'UTE B30613087-B30619167-A28012359',
            'adjudicatariocodigo' => 'B30613087/B30619167/A28012359',
            'fechaformalizacion' => '15/03/2023',
            'duracion' => '18 meses',
            'organo' => 'Consejeria de Fomento',
            'cpvcodigo' => '45000000',
            'cpvdescripcion' => 'Construccion',
            'nummodificaciones' => '0',
            'codigoOrgano' => 'A22001',
            'fechaInicio' => '01/02/2023',
        ];

        $result = $this->parser->parseMayor($record);

        $this->assertNotNull($result);
        $this->assertSame('B30613087', $result['nif_adjudicatario']);
        $this->assertLessThanOrEqual(20, mb_strlen((string) $result['nif_adjudicatario']));
    }

    public function test_parse_mayor_nif_basura_larga_devuelve_null_y_contrato_se_importa(): void
    {
        // Valor basura (nombre en lugar de NIF): >20 chars → nif_adjudicatario null,
        // pero el contrato sigue parseando (no aborta)
        $record = [
            'ejercicio' => '2023',
            'codinscripcion' => '2023/BASURA01',
            'tipocontrato' => 'SUMINISTROS',
            'procedimiento' => 'NEGOCIADO SIN PUBLICIDAD',
            'objeto' => 'Suministro con NIF basura',
            'importlicitacion' => '10000',
            'importadjudicacion' => '9500',
            'adjudicatariodescripcion' => 'VARIANMEDICALSYSTEMSIBÉRICA,SL',
            'adjudicatariocodigo' => 'VARIANMEDICALSYSTEMSIBÉRICA,SL',
            'fechaformalizacion' => '20/06/2023',
            'duracion' => '6 meses',
            'organo' => 'Consejeria de Salud',
            'cpvcodigo' => '33000000',
            'cpvdescripcion' => 'Equipamiento medico',
            'nummodificaciones' => '0',
            'codigoOrgano' => 'A23001',
            'fechaInicio' => '01/06/2023',
        ];

        $result = $this->parser->parseMayor($record);

        // El contrato debe importarse (no null)
        $this->assertNotNull($result);
        // Pero nif_adjudicatario debe ser null (valor descartado por longitud)
        $this->assertNull($result['nif_adjudicatario']);
        // El nombre del adjudicatario sí se conserva
        $this->assertSame('VARIANMEDICALSYSTEMSIBÉRICA,SL', $result['nombre_adjudicatario']);
    }

    public function test_parse_menor_nif_normal_se_mantiene_intacto(): void
    {
        // NIF estándar (9 chars): debe pasar sin cambios
        $record = [
            'CODEXPEDIENTE' => '2024/NIF001',
            'OBJETO_CONTRATO_MENOR' => 'Contrato con NIF normal',
            'UNIDADCODIGO' => '3',
            'UNIDADDESCRIPCION' => 'Consejeria de Economia',
            'CENTROCODIGO' => '',
            'CENTRODESCRIPCION' => '',
            'TIPOCONTRATO' => 'SERVICIOS',
            'CPVCODIGO' => '72000000',
            'CPVDESCRIPCION' => 'Servicios TI',
            'ADJUDICATARIOCODIGO' => 'B30613087',
            'ADJUDICATARIODESCRIPCION' => 'EMPRESA NORMAL SL',
            'FECHACONTABPAGO' => '10/04/2024',
            'IMPORTECONTABPAGO' => '1500',
            'EJERCICIONUM' => '2024',
            'TRIMESTRENUM' => '2',
        ];

        $result = $this->parser->parseMenor($record);

        $this->assertNotNull($result);
        $this->assertSame('B30613087', $result['nif_adjudicatario']);
    }
}
