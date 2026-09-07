<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Adjudicatario;
use App\Models\Contrato;
use App\Models\FuenteDatos;
use App\Models\ImportLog;
use App\Models\Organismo;
use App\Services\Regional\ExistingContractIndex;
use App\Services\Regional\MadridAytoParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cubre los dos esquemas extremos del dataset de menores del Ayuntamiento de
 * Madrid: el moderno (19 columnas, cp1252, con líneas de título por delante) y
 * el de 2018-2020 (12 columnas) guardado en CP850 (OEM de DOS), que es el caso
 * donde el Content-Type del portal miente más descaradamente.
 *
 * Los CSV de muestra son fixtures locales: los tests nunca descargan nada.
 */
class SyncMadridAytoTest extends TestCase
{
    use RefreshDatabase;

    private string $modernoCsv;

    private string $dosCsv;

    private string $anioCortoCsv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->modernoCsv = base_path('tests/fixtures/regional/mad_ayto_menores_moderno.csv');
        $this->dosCsv = base_path('tests/fixtures/regional/mad_ayto_menores_2019_dos.csv');
        $this->anioCortoCsv = base_path('tests/fixtures/regional/mad_ayto_menores_anio_corto.csv');
    }

    public function test_la_fuente_de_datos_esta_sembrada(): void
    {
        $fuente = FuenteDatos::where('slug', 'mad-ayto-menores')->first();

        $this->assertNotNull($fuente);
        $this->assertSame('csv', $fuente->tipo);
        $this->assertSame('mensual', $fuente->frecuencia);
    }

    public function test_importa_el_esquema_moderno_de_19_columnas(): void
    {
        $this->artisan('regional:sync-mad-ayto', ['file' => $this->modernoCsv])
            ->assertSuccessful();

        // 5 filas de datos; las 2 líneas de título previas a la cabecera se ignoran.
        $this->assertSame(5, Contrato::count());

        $contrato = Contrato::where('placsp_id', 'AYTMAD-2025015610')->first();
        $this->assertNotNull($contrato);
        $this->assertSame('511/2025/60303', $contrato->expediente);
        $this->assertSame('Servicios', $contrato->tipo_contrato);
        $this->assertSame('Contrato menor', $contrato->procedimiento);
        $this->assertTrue((bool) $contrato->es_menor);
        $this->assertFalse((bool) $contrato->es_clm);
        $this->assertSame('contrato_menor', $contrato->tipo_registro);
        $this->assertSame(1, $contrato->num_ofertas);
        $this->assertSame('12 meses', $contrato->duracion);
        $this->assertSame('2026-01-02', $contrato->fecha_publicacion->format('Y-m-d'));
        $this->assertSame('2025-12-23', $contrato->fecha_adjudicacion->format('Y-m-d'));
    }

    public function test_decodifica_los_acentos_del_esquema_moderno_en_cp1252(): void
    {
        $this->artisan('regional:sync-mad-ayto', ['file' => $this->modernoCsv])
            ->assertSuccessful();

        $contrato = Contrato::where('placsp_id', 'AYTMAD-2025015610')->firstOrFail();

        $this->assertStringContainsString('suscripción', $contrato->objeto);
        $this->assertSame('VICEALCALDÍA, PORTAVOZ, SEGURID. Y EMERG', $contrato->lugar_ejecucion);
    }

    public function test_importa_el_esquema_de_2019_en_encoding_dos(): void
    {
        $this->artisan('regional:sync-mad-ayto', ['file' => $this->dosCsv])
            ->assertSuccessful();

        $this->assertSame(2, Contrato::count());

        $contrato = Contrato::where('placsp_id', 'AYTMAD-M201900001')->first();
        $this->assertNotNull($contrato);

        // Si el encoding se hubiera tomado por cp1252, aquí habría mojibake
        // ("DISE¤O E INSTALACI¢N") en vez de los acentos correctos.
        $this->assertStringContainsString('DISEÑO E INSTALACIÓN DE SEÑALÉTICA', $contrato->objeto);
        $this->assertStringContainsString('AÑO 2019', $contrato->objeto);
        $this->assertSame('DISTRITO DE CHAMARTÍN', $contrato->lugar_ejecucion);
        $this->assertSame('Suministros', $contrato->tipo_contrato);
        $this->assertSame('EMT/2018/10166', $contrato->expediente);
        $this->assertSame('2019-01-02', $contrato->fecha_publicacion->format('Y-m-d'));

        $otro = Contrato::where('placsp_id', 'AYTMAD-M201900002')->firstOrFail();
        $this->assertSame('Servicios', $otro->tipo_contrato);
        $this->assertStringContainsString('BIOLÓGICAS', $otro->objeto);
    }

    public function test_lee_las_fechas_con_ano_de_dos_digitos(): void
    {
        // Los ficheros de 2022 y 2025 fechan como "03/01/25" mientras el resto
        // usa "03/01/2025", sin avisar en la cabecera. Son 11.525 filas que sin
        // esto entraban con las dos fechas a NULL y desaparecian de las
        // estadisticas, que agregan por fecha_publicacion.
        $this->artisan('regional:sync-mad-ayto', ['file' => $this->anioCortoCsv])
            ->assertSuccessful();

        $contrato = Contrato::where('placsp_id', 'AYTMAD-2024015948')->firstOrFail();

        $this->assertSame('2025-01-03', $contrato->fecha_publicacion->format('Y-m-d'));
        $this->assertSame('2024-12-09', $contrato->fecha_adjudicacion->format('Y-m-d'));

        $this->assertSame(0, Contrato::whereNull('fecha_publicacion')->count());
    }

    public function test_todos_los_contratos_cuelgan_del_nif_del_ayuntamiento(): void
    {
        $this->artisan('regional:sync-mad-ayto', ['file' => $this->modernoCsv])
            ->assertSuccessful();

        // Un único organismo: el CSV sólo trae el centro como texto, así que se
        // imputa el NIF del Ayuntamiento en vez de crear organismos sin NIF.
        $this->assertSame(1, Organismo::count());

        $organismo = Organismo::where('nif', MadridAytoParser::NIF_AYUNTAMIENTO)->first();
        $this->assertNotNull($organismo);
        $this->assertSame('L01280796', $organismo->dir3);
        $this->assertSame('ES30', $organismo->nuts);

        $this->assertSame(5, Contrato::where('nif_organo', 'P2807900B')->count());
        $this->assertSame(5, Contrato::where('nuts', 'ES300')->count());
    }

    public function test_imputa_trazabilidad_al_dataset_y_deja_el_cpv_nulo(): void
    {
        $this->artisan('regional:sync-mad-ayto', ['file' => $this->modernoCsv])
            ->assertSuccessful();

        $contrato = Contrato::where('placsp_id', 'AYTMAD-2025015610')->firstOrFail();

        $this->assertSame(
            'https://datos.madrid.es/dataset/300253-0-contratos-actividad-menores',
            $contrato->url_placsp
        );
        $this->assertNull($contrato->cpv);

        $fuente = FuenteDatos::where('slug', 'mad-ayto-menores')->firstOrFail();
        $this->assertSame($fuente->id, $contrato->fuente_datos_id);
    }

    public function test_guarda_importes_con_iva_y_estima_la_base_imponible(): void
    {
        $this->artisan('regional:sync-mad-ayto', ['file' => $this->modernoCsv])
            ->assertSuccessful();

        $contrato = Contrato::where('placsp_id', 'AYTMAD-2025015610')->firstOrFail();

        $this->assertSame('15808.00', (string) $contrato->importe_adjudicacion_con_iva);
        $this->assertSame('15808.00', (string) $contrato->importe_licitacion_con_iva);
        // 15.808 / 1,21 = 13.064,46
        $this->assertSame('13064.46', (string) $contrato->importe_adjudicacion);
    }

    public function test_maneja_objetos_multilinea_entrecomillados(): void
    {
        $this->artisan('regional:sync-mad-ayto', ['file' => $this->modernoCsv])
            ->assertSuccessful();

        $contrato = Contrato::where('placsp_id', 'AYTMAD-2025015611')->firstOrFail();

        $this->assertStringContainsString('Elaboración, suministro e instalación', $contrato->objeto);
        $this->assertStringContainsString('Segundo párrafo del objeto.', $contrato->objeto);
        $this->assertStringNotContainsString("\n", $contrato->objeto);
    }

    public function test_desambigua_los_numeros_de_registro_duplicados_en_origen(): void
    {
        $this->artisan('regional:sync-mad-ayto', ['file' => $this->modernoCsv])
            ->assertSuccessful();

        // El fixture repite el registro 2025015611 en dos filas distintas: la
        // segunda recibe un sufijo de hash de contenido y no pisa a la primera.
        $duplicados = Contrato::where('placsp_id', 'like', 'AYTMAD-2025015611%')->get();

        $this->assertCount(2, $duplicados);
        $this->assertNotSame($duplicados[0]->placsp_id, $duplicados[1]->placsp_id);
    }

    public function test_resuelve_adjudicatarios_por_nif_y_marca_pyme(): void
    {
        $this->artisan('regional:sync-mad-ayto', ['file' => $this->modernoCsv])
            ->assertSuccessful();

        // 3 NIF distintos (B78342615 se repite en dos filas) y 1 fila sin NIF.
        $this->assertSame(3, Adjudicatario::count());
        $this->assertDatabaseHas('adjudicatarios', ['nif' => 'B85893493', 'es_pyme' => false]);
        $this->assertDatabaseHas('adjudicatarios', ['nif' => 'B78342615', 'es_pyme' => true]);

        $sinNif = Contrato::where('placsp_id', 'AYTMAD-2025015612')->firstOrFail();
        $this->assertNull($sinNif->nif_adjudicatario);
        $this->assertNull($sinNif->adjudicatario_id);
    }

    public function test_extrae_el_nif_embebido_entre_parentesis_en_el_nombre(): void
    {
        $this->artisan('regional:sync-mad-ayto', ['file' => $this->modernoCsv])
            ->assertSuccessful();

        // Fila con la columna de NIF vacía y el identificador embebido en la
        // razón social: "(A28017895) ILUNIÓN SEGURIDAD, S.A.".
        $contrato = Contrato::where('placsp_id', 'AYTMAD-2025015613')->firstOrFail();

        $this->assertSame('A28017895', $contrato->nif_adjudicatario);
        $this->assertSame('ILUNIÓN SEGURIDAD, S.A.', $contrato->nombre_adjudicatario);
        $this->assertNotNull($contrato->adjudicatario_id);
    }

    /**
     * Crea un contrato "ya existente" del Ayuntamiento, como si viniera de PLACSP.
     *
     * @param  array<string, mixed>  $attrs
     */
    private function contratoExistenteEnPlacsp(array $attrs): Contrato
    {
        static $seq = 0;
        $seq++;

        $organismo = Organismo::firstOrCreate(
            ['nif' => MadridAytoParser::NIF_AYUNTAMIENTO],
            ['nombre' => 'Ayuntamiento de Madrid', 'nombre_normalizado' => 'AYUNTAMIENTO DE MADRID']
        );

        return Contrato::create(array_merge([
            'placsp_id' => 'PLACSP-MAD-'.$seq,
            'fuente_datos_id' => FuenteDatos::where('slug', 'placsp-licitaciones')->firstOrFail()->id,
            'organismo_id' => $organismo->id,
            'nif_organo' => MadridAytoParser::NIF_AYUNTAMIENTO,
            'es_menor' => true,
            'hash_contenido' => 'hash-placsp-'.$seq,
        ], $attrs));
    }

    public function test_descarta_un_contrato_que_ya_esta_en_la_base_por_placsp(): void
    {
        // Mismo contrato que la primera fila del CSV, publicado por PLACSP con
        // otro identificador, el importe con IVA en la columna sin IVA (así lo
        // guarda PLACSP para Madrid), fecha 13 dias antes y el objeto en otra
        // caja y con otros acentos.
        $this->contratoExistenteEnPlacsp([
            'importe_adjudicacion' => 15808.00,
            'fecha_publicacion' => '2025-12-20',
            'objeto' => 'CONTRATO PRIVADO PARA LA SUSCRIPCION A LA PLATAFORMA AENORMAS',
        ]);

        $this->artisan('regional:sync-mad-ayto', ['file' => $this->modernoCsv])
            ->assertSuccessful();

        // 5 filas en el CSV, una descartada por estar ya en PLACSP.
        $this->assertNull(Contrato::where('placsp_id', 'AYTMAD-2025015610')->first());
        $this->assertSame(4, Contrato::where('placsp_id', 'like', 'AYTMAD-%')->count());

        $log = ImportLog::where('tipo', 'sync-mad-ayto')->firstOrFail();
        $this->assertSame(5, $log->procesados);
        $this->assertSame(4, $log->nuevos);
        $this->assertSame(1, $log->ignorados);
        $this->assertStringContainsString('Ya presentes por otra fuente: 1', $log->notas);
    }

    public function test_no_descarta_si_importe_y_fecha_coinciden_pero_el_objeto_es_distinto(): void
    {
        // Mismo importe y misma fecha, objeto sin relación: es el falso positivo
        // que el filtro de objeto tiene que evitar (importes redondos como
        // 3.000 o 10.890 EUR se repiten mucho entre menores del mismo mes).
        $this->contratoExistenteEnPlacsp([
            'importe_adjudicacion' => 15808.00,
            'fecha_publicacion' => '2026-01-02',
            'objeto' => 'SUMINISTRO DE MOBILIARIO DE OFICINA PARA EL DISTRITO DE SALAMANCA',
        ]);

        $this->artisan('regional:sync-mad-ayto', ['file' => $this->modernoCsv])
            ->assertSuccessful();

        $this->assertNotNull(Contrato::where('placsp_id', 'AYTMAD-2025015610')->first());
        $this->assertSame(5, Contrato::where('placsp_id', 'like', 'AYTMAD-%')->count());
    }

    public function test_no_descarta_si_el_objeto_coincide_pero_la_fecha_queda_fuera_de_la_ventana(): void
    {
        $this->contratoExistenteEnPlacsp([
            'importe_adjudicacion' => 15808.00,
            // 60 dias antes: fuera de los +-45 dias de tolerancia.
            'fecha_publicacion' => '2025-11-03',
            'objeto' => 'CONTRATO PRIVADO PARA LA SUSCRIPCION A LA PLATAFORMA AENORMAS',
        ]);

        $this->artisan('regional:sync-mad-ayto', ['file' => $this->modernoCsv])
            ->assertSuccessful();

        $this->assertNotNull(Contrato::where('placsp_id', 'AYTMAD-2025015610')->first());
        $this->assertSame(5, Contrato::where('placsp_id', 'like', 'AYTMAD-%')->count());
    }

    public function test_no_se_descarta_a_si_mismo_al_reimportar(): void
    {
        // El indice excluye los contratos de la propia fuente: si no, la segunda
        // pasada veria sus propias filas como duplicadas y no actualizaria nada.
        $this->artisan('regional:sync-mad-ayto', ['file' => $this->modernoCsv])->assertSuccessful();
        $this->artisan('regional:sync-mad-ayto', ['file' => $this->modernoCsv])->assertSuccessful();

        $segundoLog = ImportLog::where('tipo', 'sync-mad-ayto')->orderByDesc('id')->firstOrFail();
        $this->assertSame(5, $segundoLog->procesados);
        $this->assertSame(5, $segundoLog->ignorados);
        $this->assertStringContainsString('Ya presentes por otra fuente: 0', $segundoLog->notas);
    }

    public function test_sigue_manteniendo_los_contratos_que_ya_habia_importado(): void
    {
        // Primera pasada: el contrato aun no esta en PLACSP y lo importamos.
        $this->artisan('regional:sync-mad-ayto', ['file' => $this->modernoCsv])->assertSuccessful();
        $this->assertNotNull(Contrato::where('placsp_id', 'AYTMAD-2025015610')->first());

        // Mas tarde PLACSP publica ese mismo contrato.
        $this->contratoExistenteEnPlacsp([
            'importe_adjudicacion' => 15808.00,
            'fecha_publicacion' => '2025-12-20',
            'objeto' => 'CONTRATO PRIVADO PARA LA SUSCRIPCION A LA PLATAFORMA AENORMAS',
        ]);

        // Segunda pasada: no se descarta el nuestro (descartarlo no borraria el
        // de PLACSP, solo dejaria nuestra copia sin actualizar nunca mas).
        $this->artisan('regional:sync-mad-ayto', ['file' => $this->modernoCsv])->assertSuccessful();

        $this->assertNotNull(Contrato::where('placsp_id', 'AYTMAD-2025015610')->first());

        $segundoLog = ImportLog::where('tipo', 'sync-mad-ayto')->orderByDesc('id')->firstOrFail();
        $this->assertStringContainsString('Ya presentes por otra fuente: 0', $segundoLog->notas);
    }

    public function test_la_ventana_y_el_umbral_de_solape_son_los_calibrados(): void
    {
        // Fijados con datos reales del CSV de 2026 (ver docblock de la clase):
        // sin filtro de objeto habria 1.806 descartes en vez de 1.337.
        $this->assertSame(45, ExistingContractIndex::WINDOW_DAYS);
        $this->assertSame(0.5, ExistingContractIndex::MIN_OVERLAP);
    }

    public function test_la_reimportacion_es_idempotente(): void
    {
        $this->artisan('regional:sync-mad-ayto', ['file' => $this->modernoCsv])->assertSuccessful();
        $this->artisan('regional:sync-mad-ayto', ['file' => $this->modernoCsv])->assertSuccessful();

        $this->assertSame(5, Contrato::count());
        $this->assertSame(0, Contrato::where('version', '>', 1)->count());
        $this->assertDatabaseCount('contratos_historial', 0);

        $segundoLog = ImportLog::where('tipo', 'sync-mad-ayto')->orderByDesc('id')->firstOrFail();
        $this->assertSame(5, $segundoLog->procesados);
        $this->assertSame(0, $segundoLog->nuevos);
        $this->assertSame(5, $segundoLog->ignorados);
    }

    public function test_registra_import_log_y_marca_la_sincronizacion(): void
    {
        $this->artisan('regional:sync-mad-ayto', ['file' => $this->modernoCsv])
            ->assertSuccessful();

        $log = ImportLog::where('tipo', 'sync-mad-ayto')->firstOrFail();
        $this->assertSame(5, $log->procesados);
        $this->assertSame(5, $log->nuevos);
        $this->assertSame(0, $log->errores);

        $fuente = FuenteDatos::where('slug', 'mad-ayto-menores')->firstOrFail();
        $this->assertNotNull($fuente->ultima_sincronizacion);
    }

    public function test_dry_run_no_escribe_nada(): void
    {
        $this->artisan('regional:sync-mad-ayto', [
            'file' => $this->modernoCsv,
            '--dry-run' => true,
        ])->assertSuccessful();

        $this->assertSame(0, Contrato::count());
        $this->assertSame(0, Organismo::count());
        $this->assertDatabaseCount('import_logs', 0);
    }

    public function test_falla_si_el_fichero_no_existe(): void
    {
        $this->artisan('regional:sync-mad-ayto', ['file' => '/no/existe/madrid.csv'])
            ->assertFailed();
    }

    public function test_respeta_el_limite_de_filas(): void
    {
        $this->artisan('regional:sync-mad-ayto', [
            'file' => $this->modernoCsv,
            '--limit' => 2,
        ])->assertSuccessful();

        $this->assertSame(2, Contrato::count());
    }
}
