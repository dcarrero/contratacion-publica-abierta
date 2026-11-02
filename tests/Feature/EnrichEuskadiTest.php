<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Contrato;
use App\Models\FuenteDatos;
use App\Models\Organismo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * El enriquecimiento de Euskadi debe consultar cada XML una sola vez: antes volvía a pedir
 * cada semana los ~85.000 del año, porque los contratos menores publican el CPV vacío y
 * nunca dejaban de parecer pendientes. El JSON es local y los XML se simulan.
 */
class EnrichEuskadiTest extends TestCase
{
    use RefreshDatabase;

    private string $json;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Sleep::fake();

        $this->json = tempnam(sys_get_temp_dir(), 'eusk').'.json';
        file_put_contents($this->json, json_encode([
            ['contratacion_expediente' => 'ORD-1', 'dataXML' => 'https://www.contratacion.euskadi.eus/test/ord-1.xml'],
            ['contratacion_expediente' => 'MEN-1', 'dataXML' => 'https://www.contratacion.euskadi.eus/test/men-1.xml'],
            ['contratacion_expediente' => 'ROTO-1', 'dataXML' => 'https://www.contratacion.euskadi.eus/test/roto-1.xml'],
        ]));

        foreach (['ORD-1', 'MEN-1', 'ROTO-1'] as $i => $exp) {
            $this->contrato('EUSK-'.$exp, $i);
        }
    }

    protected function tearDown(): void
    {
        @unlink($this->json);

        parent::tearDown();
    }

    public function test_ordinary_contract_gets_cpv_and_tender_budget(): void
    {
        $this->fakeXml();

        $this->enrich()->assertSuccessful();

        $ord = Contrato::where('placsp_id', 'EUSK-ORD-1')->firstOrFail();
        $this->assertSame('38540000-2||90400000-1', $ord->cpv);
        $this->assertEquals(990000.0, (float) $ord->importe_licitacion);
        $this->assertEquals(774900.0, (float) $ord->importe_adjudicacion);
    }

    public function test_consulted_xml_is_not_requested_again_but_failures_are(): void
    {
        $this->fakeXml();
        $this->enrich()->assertSuccessful();

        // El menor sigue sin CPV (vacío en origen) y el roto falló: antes los dos se
        // volvían a pedir siempre; ahora solo se anotan los que respondieron.
        $this->assertSame(
            ['EUSK-MEN-1', 'EUSK-ORD-1'],
            DB::table('contrato_enriquecimientos')->orderBy('placsp_id')->pluck('placsp_id')->all(),
        );

        $antes = $this->sentTo();
        $this->enrich()->assertSuccessful();
        $despues = $this->sentTo();

        $this->assertSame($antes['men-1.xml'], $despues['men-1.xml']);
        $this->assertSame($antes['ord-1.xml'], $despues['ord-1.xml']);
        $this->assertGreaterThan($antes['roto-1.xml'], $despues['roto-1.xml']);
    }

    public function test_contract_changed_after_consultation_is_requested_again(): void
    {
        $this->fakeXml();
        $this->enrich()->assertSuccessful();

        // La sincronización modifica el menor después de la consulta.
        Contrato::where('placsp_id', 'EUSK-MEN-1')->update(['updated_at' => now()->addMinute()]);

        $antes = $this->sentTo();
        $this->enrich()->assertSuccessful();
        $despues = $this->sentTo();

        $this->assertSame($antes['men-1.xml'] + 1, $despues['men-1.xml']);
        $this->assertSame($antes['ord-1.xml'], $despues['ord-1.xml']);
    }

    public function test_todos_option_ignores_the_consultation_log(): void
    {
        $this->fakeXml();
        $this->enrich()->assertSuccessful();

        $antes = $this->sentTo();
        $this->enrich(['--todos' => true])->assertSuccessful();

        $this->assertSame($antes['men-1.xml'] + 1, $this->sentTo()['men-1.xml']);
    }

    /** @param array<string, mixed> $extra */
    private function enrich(array $extra = []): \Illuminate\Testing\PendingCommand
    {
        return $this->artisan('regional:enrich-eusk', array_merge([
            '--year' => ['2026'],
            '--json' => $this->json,
            '--concurrency' => 2,
        ], $extra));
    }

    /** @return array<string, int> peticiones enviadas por URL de XML */
    private function sentTo(): array
    {
        $cuenta = ['ord-1.xml' => 0, 'men-1.xml' => 0, 'roto-1.xml' => 0];
        foreach (Http::recorded() as [$request]) {
            foreach (array_keys($cuenta) as $sufijo) {
                if (str_ends_with($request->url(), $sufijo)) {
                    $cuenta[$sufijo]++;
                }
            }
        }

        return $cuenta;
    }

    private function fakeXml(): void
    {
        $ordinario = '<?xml version="1.0" encoding="ISO-8859-1"?><contractingAnnouncement>'
            .'<contractingType desc="Tipo de contrato">Suministros</contractingType>'
            .'<adjudicationProcedure desc="Procedimiento">Abierto</adjudicationProcedure>'
            .'<budgetEstimated><budgetWithoutVAT desc="Presupuesto del contrato sin IVA">990.000</budgetWithoutVAT></budgetEstimated>'
            .'<budgetWithVAT desc="Presupuesto del contrato con IVA">1.197.900</budgetWithVAT>'
            .'<cpvs desc="CPV\'s"><cpv id="90400000-1"><name desc="CPV">Alcantarillado</name><main desc="Principal">false</main></cpv>'
            .'<cpv id="38540000-2"><name desc="CPV">Aparatos de medida</name><main desc="Principal">true</main></cpv></cpvs>'
            .'<priceWithoutVAT desc="Precio sin IVA">774.900</priceWithoutVAT><priceWithVAT desc="Precio con IVA">937.629</priceWithVAT>'
            .'<businessCif>B12345674</businessCif><businessName>Medidas S.L.</businessName>'
            .'</contractingAnnouncement>';

        $menor = '<?xml version="1.0" encoding="ISO-8859-1"?><contractingAnnouncement>'
            .'<contractingType desc="Tipo de contrato">Servicios</contractingType>'
            .'<adjudicationProcedure desc="Procedimiento">Directo/Contrato Menor</adjudicationProcedure>'
            .'<cpvs desc="CPV\'s"/>'
            .'<priceWithoutVAT desc="Precio sin IVA">1.400</priceWithoutVAT><priceWithVAT desc="Precio con IVA">1.694</priceWithVAT>'
            .'<businessCif>B87654321</businessCif><businessName>Carrozas S.L.</businessName>'
            .'</contractingAnnouncement>';

        Http::fake(function ($request) use ($ordinario, $menor) {
            return match (true) {
                str_ends_with($request->url(), 'ord-1.xml') => Http::response($ordinario),
                str_ends_with($request->url(), 'men-1.xml') => Http::response($menor),
                default => Http::response('', 500),
            };
        });
    }

    private function contrato(string $placspId, int $seq): void
    {
        $organismo = Organismo::firstOrCreate(
            ['nif' => 'S4833001C'],
            ['nombre' => 'Gobierno Vasco', 'nombre_normalizado' => 'GOBIERNO VASCO'],
        );

        Contrato::create([
            'placsp_id' => $placspId,
            'fuente_datos_id' => FuenteDatos::firstOrFail()->id,
            'organismo_id' => $organismo->id,
            'nif_organo' => 'S4833001C',
            'es_menor' => str_contains($placspId, 'MEN'),
            'hash_contenido' => 'hash-'.$seq,
            'updated_at' => now()->subDay(),
            'created_at' => now()->subDay(),
        ]);
    }
}
