<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Contrato;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La sync de Castilla y León leía el CSV a través del filtro convert.iconv, que con el
 * iconv de glibc fallaba al partirse un carácter acentuado entre dos bloques del stream,
 * aunque el fichero fuese UTF-8 válido. El fixture es lo bastante grande para cruzar
 * muchos límites de bloque y lleva además un byte inválido de verdad.
 *
 * Ojo: el iconv de macOS no reproduce el fallo original (pasa también con el código
 * antiguo); el de glibc, el del contenedor Docker, sí. Se comprobó allí con el CSV real.
 */
class SyncCastillaYLeonTest extends TestCase
{
    use RefreshDatabase;

    private string $csv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->csv = tempnam(sys_get_temp_dir(), 'cyl').'.csv';

        $cabecera = 'codigo_expediente;titulo;organo;procedimiento_de_adjudicacion;tipo_de_contrato;'
            .'presupuesto_de_licitacion_iva_incluido;no_peticion_de_ofertas;'
            .'presupuesto_de_adjudicacion_iva_incluido;fecha_aprobacion_de_gasto;'
            .'identidad_del_adjudicatario;nif_adjudicatario;plazo_de_ejecucion_meses;'
            .'plazo_de_ejecucion_dias;enlace_de_publicacion';

        $lineas = [$cabecera];
        for ($i = 1; $i <= 400; $i++) {
            $lineas[] = sprintf(
                'B2026/%06d;Campaña de promoción en León número %d: añadidos, señalización y diseño;'
                .'Instituto para la Competitividad Empresarial de Castilla y León;Contrato Menor;Servicios;'
                .'1000.50;0;1000.50;2026-09-30;SOCIEDAD ESPAÑOLA DE RADIODIFUSIÓN S.L.U;B28016970;2;0;'
                .'https://contrataciondelestado.es/wps/poc?uri=deeplink:detalle_licitacion&idEvl=x%d',
                $i, $i, $i,
            );
        }
        // Byte suelto no válido en UTF-8 (0xFF) en mitad de un título.
        $lineas[] = "B2026/999999;Obra con byte roto \xFF aquí;Ayuntamiento de Ávila;Contrato Menor;Obras;"
            .'500;0;500;2026-09-30;CONSTRUCCIONES PÉREZ S.L.;B12345674;1;0;'
            .'https://contrataciondelestado.es/wps/poc?uri=deeplink:detalle_licitacion&idEvl=roto';

        file_put_contents($this->csv, implode("\n", $lineas)."\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->csv);

        parent::tearDown();
    }

    public function test_reads_large_utf8_file_without_iconv_errors(): void
    {
        $this->artisan('regional:sync-cyl', ['file' => $this->csv, '--dry-run' => true])
            ->expectsOutputToContain('Resumen importacion Castilla y Leon')
            ->assertSuccessful();
    }

    public function test_invalid_bytes_are_scrubbed_and_text_keeps_its_accents(): void
    {
        $this->artisan('regional:sync-cyl', ['file' => $this->csv])->assertSuccessful();

        $this->assertSame(401, Contrato::count());

        $roto = Contrato::where('objeto', 'like', 'Obra con byte roto%')->firstOrFail();
        $this->assertTrue(mb_check_encoding($roto->objeto, 'UTF-8'));
        $this->assertStringContainsString('aquí', $roto->objeto);

        $normal = Contrato::where('objeto', 'like', 'Campaña de promoción en León número 7:%')->firstOrFail();
        $this->assertStringContainsString('señalización y diseño', $normal->objeto);
    }
}
