<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Contrato;
use App\Models\ContratoHistorial;
use App\Models\FuenteDatos;
use App\Models\Organismo;
use App\Services\ContratoImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ContratoImporterTest extends TestCase
{
    use RefreshDatabase;

    private ContratoImporter $importer;

    private FuenteDatos $fuente;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importer = $this->app->make(ContratoImporter::class);

        $this->fuente = FuenteDatos::create([
            'nombre' => 'PLACSP - Licitaciones',
            'slug' => 'placsp-licitaciones',
            'url' => 'https://contrataciondelestado.es/sindicacion',
            'tipo' => 'atom',
            'frecuencia' => 'diaria',
            'activo' => true,
        ]);
    }

    /**
     * Construye un array de datos mínimo válido para importar un contrato.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function makeData(array $overrides = []): array
    {
        return array_merge([
            'placsp_id' => 'TEST-001',
            'nif_organo' => 'P4500000A',
            'nombre_organo' => 'Diputación Provincial de Toledo',
            'objeto' => 'Suministro de material de oficina',
            'tipo_contrato' => 'SU',
            'procedimiento' => 'OA',
            'estado' => 'PUB',
            'importe_licitacion' => 10000.00,
            'nuts' => 'ES425',
            'fecha_publicacion' => '2026-01-15',
            'url_placsp' => 'https://contrataciondelestado.es/wps/poc?uri=deeplink:detalle_licitacion&idEvil=TEST-001',
            'fuente_datos_id' => $this->fuente->id,
            'tipo_registro' => 'licitacion',
        ], $overrides);
    }

    public function test_enriquece_nuts_del_organismo_desde_el_contrato(): void
    {
        $importer = app(ContratoImporter::class);

        $importer->import([
            'placsp_id' => 'TEST-NUTS-001',
            'nif_organo' => 'P4500000A',
            'nombre_organo' => 'Ayuntamiento de Prueba',
            'nuts' => 'ES424',
        ]);

        $organismo = Organismo::where('nif', 'P4500000A')->first();
        $this->assertNotNull($organismo);
        $this->assertSame('ES42', $organismo->nuts);
    }

    public function test_no_sobreescribe_nuts_existente_del_organismo(): void
    {
        // Crear organismo ya con NUTS asignado
        Organismo::create([
            'nif' => 'P4500000B',
            'nombre' => 'Organismo con NUTS previo',
            'nombre_normalizado' => 'organismo con nuts previo',
            'nuts' => 'ES41',
        ]);

        $importer = app(ContratoImporter::class);

        $importer->import([
            'placsp_id' => 'TEST-NUTS-002',
            'nif_organo' => 'P4500000B',
            'nombre_organo' => 'Organismo con NUTS previo',
            'nuts' => 'ES424',
        ]);

        $organismo = Organismo::where('nif', 'P4500000B')->first();
        $this->assertNotNull($organismo);
        // El NUTS original no debe ser sobreescrito
        $this->assertSame('ES41', $organismo->nuts);
    }

    public function test_es_clm_derivado_de_nuts_es42(): void
    {
        // Contrato CLM (NUTS ES425 - Toledo)
        $this->importer->import($this->makeData(['nuts' => 'ES425', 'placsp_id' => 'CLM-001']));
        $clm = Contrato::where('placsp_id', 'CLM-001')->first();
        $this->assertNotNull($clm);
        $this->assertTrue($clm->es_clm);

        // Contrato no CLM (NUTS ES230 - La Rioja)
        $this->importer->import($this->makeData(['nuts' => 'ES230', 'placsp_id' => 'NOCLM-001']));
        $noClm = Contrato::where('placsp_id', 'NOCLM-001')->first();
        $this->assertNotNull($noClm);
        $this->assertFalse($noClm->es_clm);
    }

    public function test_import_crea_contrato_con_version_inicial(): void
    {
        $result = $this->importer->import($this->makeData(['placsp_id' => 'TEST-NEW-001']));

        $this->assertEquals('created', $result);

        $contrato = Contrato::where('placsp_id', 'TEST-NEW-001')->first();
        $this->assertNotNull($contrato);
        $this->assertEquals(1, $contrato->version);
        $this->assertEquals(0, ContratoHistorial::count());
    }

    public function test_import_sin_cambios_no_crea_historial(): void
    {
        $data = $this->makeData(['placsp_id' => 'TEST-SKIP-001']);

        // Primera importación
        $this->importer->import($data);

        // Segunda importación con los mismos datos
        $result = $this->importer->import($data);

        $this->assertEquals('skipped', $result);
        $this->assertEquals(0, ContratoHistorial::count());

        $contrato = Contrato::where('placsp_id', 'TEST-SKIP-001')->first();
        $this->assertEquals(1, $contrato->version);
    }

    public function test_import_con_cambios_versiona_e_historia(): void
    {
        $data = $this->makeData(['placsp_id' => 'TEST-UPD-001', 'objeto' => 'Objeto original']);

        // Primera importación
        $this->importer->import($data);

        $original = Contrato::where('placsp_id', 'TEST-UPD-001')->first();
        $this->assertEquals(1, $original->version);

        // Segunda importación con campo distinto
        $dataModificada = array_merge($data, ['objeto' => 'Objeto modificado']);
        $result = $this->importer->import($dataModificada);

        $this->assertEquals('updated', $result);

        $actualizado = Contrato::where('placsp_id', 'TEST-UPD-001')->first();
        $this->assertEquals(2, $actualizado->version);
        $this->assertEquals('Objeto modificado', $actualizado->objeto);

        $this->assertEquals(1, ContratoHistorial::count());
        $historial = ContratoHistorial::first();
        $this->assertEquals($actualizado->id, $historial->contrato_id);
        $this->assertEquals('TEST-UPD-001', $historial->placsp_id);
    }

    // ---------------------------------------------------------------------------
    // Fase A — nuevos casos: NIF normalización y aliases de adjudicatario
    // ---------------------------------------------------------------------------

    public function test_nif_organo_en_minusculas_se_normaliza_a_mayusculas(): void
    {
        // nif_organo llega con espacios y minúsculas: debe guardarse en mayúsculas sin espacios
        $this->importer->import($this->makeData([
            'placsp_id' => 'NIF-LOWER-001',
            'nif_organo' => '  p4500001b  ',
            'nombre_organo' => 'Organismo con NIF en minúsculas',
        ]));

        $organismo = \App\Models\Organismo::where('nif', 'P4500001B')->first();
        $this->assertNotNull($organismo, 'El organismo no se creó con el NIF normalizado.');
        $this->assertSame('P4500001B', $organismo->nif);
    }

    public function test_mismo_adjudicatario_dos_variantes_nombre_genera_un_solo_registro_y_dos_aliases(): void
    {
        $nif = 'B99999991';

        // Primera importación: nombre "EMPRESA EJEMPLO, S.L."
        $this->importer->import($this->makeData([
            'placsp_id' => 'ADJ-ALIAS-001',
            'nif_adjudicatario' => $nif,
            'nombre_adjudicatario' => 'EMPRESA EJEMPLO, S.L.',
        ]));

        // Segunda importación (mismo NIF, nombre distinto): "Empresa Ejemplo SL"
        $this->importer->import($this->makeData([
            'placsp_id' => 'ADJ-ALIAS-002',
            'nif_adjudicatario' => $nif,
            'nombre_adjudicatario' => 'Empresa Ejemplo SL',
        ]));

        // Debe existir un único Adjudicatario con ese NIF
        $this->assertSame(1, \App\Models\Adjudicatario::where('nif', $nif)->count());

        // Deben existir dos alias distintos
        $adjudicatario = \App\Models\Adjudicatario::where('nif', $nif)->first();
        $this->assertNotNull($adjudicatario);

        $aliases = \App\Models\AdjudicatarioAlias::where('adjudicatario_id', $adjudicatario->id)
            ->orderBy('nombre_variante')
            ->pluck('nombre_variante')
            ->toArray();

        $this->assertCount(2, $aliases);
        $this->assertContains('EMPRESA EJEMPLO, S.L.', $aliases);
        $this->assertContains('Empresa Ejemplo SL', $aliases);
    }

    public function test_mismo_adjudicatario_mismo_nombre_incrementa_veces_visto(): void
    {
        $nif = 'B99999992';
        $nombre = 'PROVEEDOR REPETIDO, S.A.';

        $this->importer->import($this->makeData([
            'placsp_id' => 'ADJ-VECES-001',
            'nif_adjudicatario' => $nif,
            'nombre_adjudicatario' => $nombre,
        ]));

        $this->importer->import($this->makeData([
            'placsp_id' => 'ADJ-VECES-002',
            'nif_adjudicatario' => $nif,
            'nombre_adjudicatario' => $nombre,
        ]));

        $adjudicatario = \App\Models\Adjudicatario::where('nif', $nif)->first();
        $this->assertNotNull($adjudicatario);

        // Con el mismo nombre solo debe haber un alias con veces_visto = 2
        $alias = \App\Models\AdjudicatarioAlias::where('adjudicatario_id', $adjudicatario->id)
            ->where('nombre_variante', $nombre)
            ->first();

        $this->assertNotNull($alias);
        $this->assertSame(2, $alias->veces_visto);
        $this->assertSame(1, \App\Models\AdjudicatarioAlias::where('adjudicatario_id', $adjudicatario->id)->count());
    }

    // ---------------------------------------------------------------------------
    // Fase B — Plan 012: cache de resolución + importBatch
    // ---------------------------------------------------------------------------

    /**
     * Verifica que el mismo NIF de organismo no lanza un SELECT adicional en la
     * segunda llamada (el cache de instancia absorbe la consulta).
     */
    public function test_organismo_cache_evita_consultas_repetidas_para_mismo_nif(): void
    {
        DB::enableQueryLog();

        // Primera importación: crea el organismo (firstOrCreate → INSERT)
        $this->importer->import($this->makeData([
            'placsp_id' => 'CACHE-ORG-001',
            'nif_organo' => 'P9900001C',
            'nombre_organo' => 'Organismo Cache Test',
        ]));

        $queriesAfterFirst = DB::getQueryLog();
        DB::flushQueryLog();

        // Segunda importación con el MISMO nif_organo
        $this->importer->import($this->makeData([
            'placsp_id' => 'CACHE-ORG-002',
            'nif_organo' => 'P9900001C',
            'nombre_organo' => 'Organismo Cache Test',
        ]));

        $queriesAfterSecond = DB::getQueryLog();
        DB::disableQueryLog();

        // In the first import there must be at least one organismo query (firstOrCreate)
        $firstOrgQueries = array_filter(
            $queriesAfterFirst,
            fn ($q) => str_contains(strtolower($q['query']), 'organismos')
        );
        $this->assertNotEmpty($firstOrgQueries, 'Primera fila debería consultar la tabla organismos.');

        // In the second import there must be ZERO organismo queries (cache hit)
        $secondOrgQueries = array_filter(
            $queriesAfterSecond,
            fn ($q) => str_contains(strtolower($q['query']), 'organismos')
        );
        $this->assertEmpty($secondOrgQueries, 'Segunda fila NO debería consultar organismos (cache hit).');

        // Both contracts must exist
        $this->assertEquals(1, Organismo::where('nif', 'P9900001C')->count());
        $this->assertEquals(2, Contrato::whereIn('placsp_id', ['CACHE-ORG-001', 'CACHE-ORG-002'])->count());
    }

    /**
     * Verifica que importBatch procesa correctamente filas mixtas:
     * nuevos, actualizados y sin cambios. Los conteos deben coincidir con
     * llamadas individuales a import() y el historial debe quedar igual.
     */
    public function test_import_batch_mixto_created_updated_skipped_e_historial(): void
    {
        // Pre-seed: un contrato sin cambios y uno que será actualizado
        $this->importer->import($this->makeData(['placsp_id' => 'BATCH-SKIP-001', 'objeto' => 'Objeto sin cambio']));
        $this->importer->import($this->makeData(['placsp_id' => 'BATCH-UPD-001', 'objeto' => 'Objeto original']));

        $this->assertEquals(0, ContratoHistorial::count(), 'Sin historial antes del batch.');

        // Batch de 4 filas: 1 nuevo, 1 skip, 1 update, 1 nuevo adicional
        $counts = $this->importer->importBatch([
            $this->makeData(['placsp_id' => 'BATCH-NEW-001', 'objeto' => 'Contrato nuevo A']),
            $this->makeData(['placsp_id' => 'BATCH-SKIP-001', 'objeto' => 'Objeto sin cambio']),  // sin cambio
            $this->makeData(['placsp_id' => 'BATCH-UPD-001', 'objeto' => 'Objeto modificado']),   // cambio
            $this->makeData(['placsp_id' => 'BATCH-NEW-002', 'objeto' => 'Contrato nuevo B']),
        ]);

        $this->assertEquals(2, $counts['created'], 'Deben crearse 2 contratos nuevos.');
        $this->assertEquals(1, $counts['updated'], 'Debe actualizarse 1 contrato.');
        $this->assertEquals(1, $counts['skipped'], 'Debe saltarse 1 contrato sin cambios.');

        // Los 4 nuevos contratos existen (2 pre-seeded + 2 nuevos)
        $this->assertEquals(4, Contrato::whereIn('placsp_id', [
            'BATCH-SKIP-001', 'BATCH-UPD-001', 'BATCH-NEW-001', 'BATCH-NEW-002',
        ])->count());

        // El contrato actualizado tiene versión 2
        $updated = Contrato::where('placsp_id', 'BATCH-UPD-001')->first();
        $this->assertEquals(2, $updated->version);
        $this->assertEquals('Objeto modificado', $updated->objeto);

        // Exactamente 1 entrada en historial (el contrato actualizado)
        $this->assertEquals(1, ContratoHistorial::count());
        $historial = ContratoHistorial::first();
        $this->assertEquals($updated->id, $historial->contrato_id);
        $this->assertEquals('BATCH-UPD-001', $historial->placsp_id);
    }

    /**
     * Verifica que importBatch con un lote vacío devuelve conteos en cero sin errores.
     */
    public function test_import_batch_vacio_devuelve_ceros(): void
    {
        $counts = $this->importer->importBatch([]);

        $this->assertEquals(['created' => 0, 'updated' => 0, 'skipped' => 0], $counts);
    }

    /**
     * Verifica que resetCache() limpia las cachés y permite re-resolver organismos
     * (útil entre ejecuciones independientes en el mismo proceso).
     */
    public function test_reset_cache_permite_re_resolver_organismo(): void
    {
        $nif = 'P9900002D';

        // Primera importación: llena la caché
        $this->importer->import($this->makeData([
            'placsp_id' => 'RESET-001',
            'nif_organo' => $nif,
        ]));

        $this->importer->resetCache();

        // Tras resetCache, debe volver a consultar la BD (no lanza excepción)
        DB::enableQueryLog();
        $this->importer->import($this->makeData([
            'placsp_id' => 'RESET-002',
            'nif_organo' => $nif,
        ]));
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $orgQueries = array_filter(
            $queries,
            fn ($q) => str_contains(strtolower($q['query']), 'organismos')
        );

        $this->assertNotEmpty($orgQueries, 'Tras resetCache debe volver a consultar organismos.');
        $this->assertEquals(1, Organismo::where('nif', $nif)->count());
    }
}
