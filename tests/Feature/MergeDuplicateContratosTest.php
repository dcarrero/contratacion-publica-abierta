<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Contrato;
use App\Models\ContratoHistorial;
use App\Models\FuenteDatos;
use App\Models\Organismo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * data:merge-duplicados borra filas, así que estos tests cubren sobre todo las
 * garantías: no toca nada sin --force, no se lleva por delante el historial
 * (la FK es ON DELETE CASCADE), no fusiona dentro de la misma fuente y no
 * inventa fusiones cuando el objeto no se parece.
 */
class MergeDuplicateContratosTest extends TestCase
{
    use RefreshDatabase;

    private const NIF = 'P2807900B';

    private Organismo $organismo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->organismo = Organismo::create([
            'nif' => self::NIF,
            'nombre' => 'Ayuntamiento de Madrid',
            'nombre_normalizado' => 'AYUNTAMIENTO DE MADRID',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function contrato(string $fuenteSlug, array $attrs): Contrato
    {
        static $seq = 0;
        $seq++;

        return Contrato::create(array_merge([
            'placsp_id' => strtoupper($fuenteSlug).'-'.$seq,
            'fuente_datos_id' => FuenteDatos::where('slug', $fuenteSlug)->firstOrFail()->id,
            'organismo_id' => $this->organismo->id,
            'nif_organo' => self::NIF,
            'objeto' => 'SUMINISTRO DE MATERIAL DE OFICINA PARA EL DISTRITO DE CHAMARTIN',
            'importe_adjudicacion' => 15808.00,
            'fecha_publicacion' => '2026-01-02',
            'es_menor' => true,
            'hash_contenido' => 'hash-'.$seq,
        ], $attrs));
    }

    /**
     * Pareja típica: PLACSP con trazabilidad completa frente al CSV municipal.
     *
     * @return array{0: Contrato, 1: Contrato}
     */
    private function parejaDuplicada(): array
    {
        $placsp = $this->contrato('placsp-licitaciones', [
            'placsp_id' => 'PLACSP-1',
            'cpv' => '30192000',
            'nuts' => 'ES300',
            'url_placsp' => 'https://contrataciondelestado.es/wps/poc?uri=deeplink:detalle:1',
            'url_xml' => 'https://contrataciondelestado.es/1.xml',
            'expediente' => '105/2025/02969',
            'id_plataforma' => 'PLACE-1',
        ]);

        $municipal = $this->contrato('mad-ayto-menores', [
            'placsp_id' => 'AYTMAD-2025015610',
            // Mismo expediente: es la corroboración que exige el comando.
            'expediente' => '105/2025/02969',
            'fecha_publicacion' => '2026-01-20',
            'objeto' => 'Suministro de material de oficina para el distrito de Chamartín',
            'nuts' => 'ES300',
            'url_placsp' => 'https://datos.madrid.es/dataset/300253-0-contratos-actividad-menores',
            // Datos que PLACSP no trae y que hay que rescatar antes de borrar.
            'lugar_ejecucion' => 'DISTRITO DE CHAMARTIN',
            'num_ofertas' => 3,
        ]);

        return [$placsp, $municipal];
    }

    public function test_exige_acotar_el_alcance(): void
    {
        $this->parejaDuplicada();

        $this->artisan('data:merge-duplicados')->assertFailed();

        $this->assertSame(2, Contrato::count());
    }

    public function test_falla_si_la_fuente_no_existe(): void
    {
        $this->artisan('data:merge-duplicados', ['--fuente' => 'no-existe'])->assertFailed();
    }

    public function test_sin_force_no_modifica_nada(): void
    {
        $this->parejaDuplicada();

        $this->artisan('data:merge-duplicados', ['--nif' => self::NIF])
            ->expectsOutputToContain('MODO INFORME')
            ->assertSuccessful();

        $this->assertSame(2, Contrato::count());
        $this->assertNotNull(Contrato::where('placsp_id', 'AYTMAD-2025015610')->first());
        $this->assertNull(Contrato::where('placsp_id', 'PLACSP-1')->first()->lugar_ejecucion);
    }

    public function test_conserva_el_contrato_con_mas_trazabilidad(): void
    {
        $this->parejaDuplicada();

        $this->artisan('data:merge-duplicados', ['--nif' => self::NIF, '--force' => true])
            ->assertSuccessful();

        $this->assertSame(1, Contrato::count());

        $superviviente = Contrato::firstOrFail();
        $this->assertSame('PLACSP-1', $superviviente->placsp_id);
        $this->assertSame('30192000', $superviviente->cpv);
    }

    public function test_traslada_al_superviviente_los_campos_que_le_faltaban(): void
    {
        $this->parejaDuplicada();

        $this->artisan('data:merge-duplicados', ['--nif' => self::NIF, '--force' => true])
            ->assertSuccessful();

        $superviviente = Contrato::firstOrFail();

        // Estaban sólo en la fila municipal.
        $this->assertSame('DISTRITO DE CHAMARTIN', $superviviente->lugar_ejecucion);
        $this->assertSame(3, $superviviente->num_ofertas);

        // Y no se pisa lo que ya tenía.
        $this->assertSame(
            'https://contrataciondelestado.es/wps/poc?uri=deeplink:detalle:1',
            $superviviente->url_placsp
        );
        $this->assertSame(2, $superviviente->version);
    }

    public function test_no_deja_historial_huerfano_y_conserva_el_del_borrado(): void
    {
        [$placsp, $municipal] = $this->parejaDuplicada();

        ContratoHistorial::create([
            'contrato_id' => $municipal->id,
            'placsp_id' => $municipal->placsp_id,
            'datos_json' => json_encode(['version' => 'anterior del municipal']),
        ]);

        $this->artisan('data:merge-duplicados', ['--nif' => self::NIF, '--force' => true])
            ->assertSuccessful();

        // La FK es ON DELETE CASCADE: sin reasignar, esta fila habría desaparecido.
        $historial = ContratoHistorial::all();
        $this->assertCount(2, $historial);

        foreach ($historial as $fila) {
            $this->assertSame($placsp->id, $fila->contrato_id);
        }

        // Una es el historial que ya tenía el municipal...
        $this->assertTrue($historial->contains(
            fn ($h) => str_contains((string) json_encode($h->datos_json), 'anterior del municipal')
        ));

        // ...y la otra, el volcado del propio contrato borrado.
        $volcado = $historial->first(
            fn ($h) => $h->placsp_id === 'AYTMAD-2025015610'
                && ! str_contains((string) json_encode($h->datos_json), 'anterior del municipal')
        );
        $this->assertNotNull($volcado);
        $this->assertSame('AYTMAD-2025015610', $volcado->placsp_id);
    }

    public function test_no_fusiona_sin_expediente_ni_nif_de_adjudicatario_en_comun(): void
    {
        // Caso real detectado en el primer informe sobre P2807900B: dos
        // colaboraciones distintas de la Banda Sinfónica Municipal, con objeto
        // casi calcado (es una plantilla), importes que casan por el IVA y
        // fechas dentro de la ventana. Sólo el expediente y el NIF los separan.
        $this->contrato('placsp-menores', [
            'placsp_id' => 'PLACSP-BANDA-1',
            'objeto' => 'Contratación de una colaboración en la categoría de clarinete bajo para las actuaciones de la Banda Sinfónica Municipal de Madrid',
            'expediente' => '511/2025/41819',
            'nif_adjudicatario' => '20858279Q',
        ]);
        $this->contrato('bquant-bootstrap', [
            'placsp_id' => 'BQUANT-BANDA-2',
            'objeto' => 'contratación de una colaboración en la categoría de percusión para las actuaciones de la Banda Sinfónica Municipal de Madrid',
            'expediente' => '511/2025/52405',
            'nif_adjudicatario' => 'B99469298',
            'fecha_publicacion' => '2026-02-03',
        ]);

        $this->artisan('data:merge-duplicados', ['--nif' => self::NIF, '--force' => true])
            ->expectsOutputToContain('sin expediente ni NIF de adjudicatario en común')
            ->assertSuccessful();

        $this->assertSame(2, Contrato::count());
    }

    public function test_fusiona_corroborando_solo_por_nif_de_adjudicatario(): void
    {
        // Sin expediente en la fila municipal, pero mismo adjudicatario.
        $this->contrato('placsp-licitaciones', [
            'placsp_id' => 'PLACSP-NIF',
            'expediente' => '105/2025/00001',
            'nif_adjudicatario' => 'B78342615',
            'cpv' => '30192000',
        ]);
        $this->contrato('mad-ayto-menores', [
            'placsp_id' => 'AYTMAD-NIF',
            'expediente' => null,
            'nif_adjudicatario' => 'b78342615',
        ]);

        $this->artisan('data:merge-duplicados', ['--nif' => self::NIF, '--force' => true])
            ->assertSuccessful();

        $this->assertSame(1, Contrato::count());
        $this->assertSame('PLACSP-NIF', Contrato::firstOrFail()->placsp_id);
    }

    public function test_no_fusiona_contratos_de_la_misma_fuente(): void
    {
        // Dos filas idénticas de la MISMA fuente pueden ser contratos reales
        // distintos (el registro municipal de Madrid repite números).
        $this->contrato('mad-ayto-menores', ['placsp_id' => 'AYTMAD-1']);
        $this->contrato('mad-ayto-menores', ['placsp_id' => 'AYTMAD-2']);

        $this->artisan('data:merge-duplicados', ['--nif' => self::NIF, '--force' => true])
            ->assertSuccessful();

        $this->assertSame(2, Contrato::count());
    }

    public function test_no_fusiona_si_el_objeto_no_se_parece(): void
    {
        $this->contrato('placsp-licitaciones', ['placsp_id' => 'PLACSP-X']);
        $this->contrato('mad-ayto-menores', [
            'placsp_id' => 'AYTMAD-X',
            'objeto' => 'OBRAS DE REPARACION DEL PAVIMENTO EN LA CALLE ALCALA',
        ]);

        $this->artisan('data:merge-duplicados', ['--nif' => self::NIF, '--force' => true])
            ->assertSuccessful();

        $this->assertSame(2, Contrato::count());
    }

    public function test_no_fusiona_fuera_de_la_ventana_de_fechas(): void
    {
        $this->contrato('placsp-licitaciones', ['placsp_id' => 'PLACSP-Y']);
        $this->contrato('mad-ayto-menores', [
            'placsp_id' => 'AYTMAD-Y',
            'fecha_publicacion' => '2026-06-01',
        ]);

        $this->artisan('data:merge-duplicados', ['--nif' => self::NIF, '--force' => true])
            ->assertSuccessful();

        $this->assertSame(2, Contrato::count());
    }

    public function test_no_toca_otros_organos(): void
    {
        $this->parejaDuplicada();

        $otro = Organismo::create([
            'nif' => 'P4500000A',
            'nombre' => 'Otro ayuntamiento',
            'nombre_normalizado' => 'OTRO AYUNTAMIENTO',
        ]);

        foreach (['placsp-licitaciones', 'mad-ayto-menores'] as $slug) {
            $this->contrato($slug, [
                'organismo_id' => $otro->id,
                'nif_organo' => $otro->nif,
            ]);
        }

        $this->artisan('data:merge-duplicados', ['--nif' => self::NIF, '--force' => true])
            ->assertSuccessful();

        // Se fusiona la pareja del alcance y se dejan intactos los del otro NIF.
        $this->assertSame(1, Contrato::where('nif_organo', self::NIF)->count());
        $this->assertSame(2, Contrato::where('nif_organo', $otro->nif)->count());
    }

    public function test_acota_por_fuente(): void
    {
        $this->parejaDuplicada();

        $this->artisan('data:merge-duplicados', [
            '--fuente' => 'mad-ayto-menores',
            '--force' => true,
        ])->assertSuccessful();

        $this->assertSame(1, Contrato::count());
        $this->assertSame('PLACSP-1', Contrato::firstOrFail()->placsp_id);
    }

    public function test_es_idempotente(): void
    {
        $this->parejaDuplicada();

        $this->artisan('data:merge-duplicados', ['--nif' => self::NIF, '--force' => true])
            ->assertSuccessful();
        $this->artisan('data:merge-duplicados', ['--nif' => self::NIF, '--force' => true])
            ->assertSuccessful();

        $this->assertSame(1, Contrato::count());
        // La segunda pasada no vuelve a versionar ni a añadir historial.
        $this->assertSame(2, Contrato::firstOrFail()->version);
        $this->assertSame(1, ContratoHistorial::count());
    }

    public function test_respeta_el_tamano_maximo_de_grupo(): void
    {
        // Tres filas que casan entre sí, de dos fuentes distintas: con el
        // máximo por defecto (parejas) el grupo se salta entero.
        $this->contrato('placsp-licitaciones', ['placsp_id' => 'PLACSP-Z', 'expediente' => 'E-1']);
        $this->contrato('mad-ayto-menores', ['placsp_id' => 'AYTMAD-Z1', 'expediente' => 'E-1']);
        $this->contrato('mad-ayto-menores', ['placsp_id' => 'AYTMAD-Z2', 'expediente' => 'E-1']);

        $this->artisan('data:merge-duplicados', ['--nif' => self::NIF, '--force' => true])
            ->expectsOutputToContain('grupo mayor que --max-grupo')
            ->assertSuccessful();

        $this->assertSame(3, Contrato::count());
    }

    public function test_informa_del_grupo_detectado_en_modo_informe(): void
    {
        $this->parejaDuplicada();

        // La línea de ejemplo dice qué se conserva, qué se borra y qué se
        // traslada, para poder revisarlo antes de ejecutar con --force.
        $this->artisan('data:merge-duplicados', ['--nif' => self::NIF])
            ->expectsOutputToContain('CONSERVA PLACSP-1 (traz. 6) | BORRA AYTMAD-2025015610')
            ->assertSuccessful();
    }
}
