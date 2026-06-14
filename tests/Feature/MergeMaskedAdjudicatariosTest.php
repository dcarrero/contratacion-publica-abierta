<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\MergeMaskedAdjudicatarios;
use App\Models\Adjudicatario;
use App\Models\Contrato;
use App\Models\FuenteDatos;
use App\Models\Organismo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for nif:merge-masked — memory-safe matcher with visible-digit tier.
 *
 * CRITICAL: every test that calls ->assertExitCode(0) also verifies no data
 * corruption. Guards tested:
 *   - UTEs (names containing "||") are never merged
 *   - Individuals (masked DNI ending with letter) are never merged
 *   - Ambiguous digit matches (>1 candidate) are never merged
 *   - Name dissimilarity prevents digit-only matches
 */
class MergeMaskedAdjudicatariosTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    // ----------------------------------------------------------------
    // Helper factories
    // ----------------------------------------------------------------

    private function fuente(): FuenteDatos
    {
        return FuenteDatos::where('slug', 'bquant-bootstrap')->firstOrFail();
    }

    private function organismo(): Organismo
    {
        return Organismo::firstOrCreate(
            ['nif' => 'Q2800001A'],
            ['nombre' => 'Organismo Test', 'nombre_normalizado' => 'ORGANISMO TEST']
        );
    }

    private function adjudicatario(string $nif, string $nombre, int $contratos = 1, float $importe = 1000.0): Adjudicatario
    {
        $cmd = new MergeMaskedAdjudicatarios;

        return Adjudicatario::create([
            'nif' => $nif,
            'nombre' => $nombre,
            'nombre_normalizado' => $cmd->normalize($nombre),
            'total_contratos' => $contratos,
            'total_importe' => $importe,
        ]);
    }

    private function contrato(int $adjudicatarioId): Contrato
    {
        self::$seq++;

        return Contrato::create([
            'placsp_id' => 'MASK-TEST-'.self::$seq,
            'fuente_datos_id' => $this->fuente()->id,
            'organismo_id' => $this->organismo()->id,
            'adjudicatario_id' => $adjudicatarioId,
            'nif_organo' => 'Q2800001A',
            'hash_contenido' => 'hash-'.self::$seq,
        ]);
    }

    // ----------------------------------------------------------------
    // Case 1: Exact normalized-name match (preserves current behaviour)
    // ----------------------------------------------------------------

    public function test_exact_name_match_merges(): void
    {
        // Masked adjudicatario: same normalized name as real
        $real = $this->adjudicatario('B12345678', 'Papelería Central, S.L.', contratos: 5, importe: 50000);
        $masked = $this->adjudicatario('***  ***', 'Papelería Central, S.L.', contratos: 2, importe: 8000);
        $contrato = $this->contrato($masked->id);

        $this->artisan('nif:merge-masked')
            ->assertExitCode(0);

        // Masked record deleted
        $this->assertNull(Adjudicatario::find($masked->id));
        // Contract reassigned to real
        $contrato->refresh();
        $this->assertSame($real->id, $contrato->adjudicatario_id);
        // Alias created (names are the same after normalisation so lower check applies)
        // The alias check: mb_strtolower differs? No — same name, so no alias created.
        $this->assertSame(1, Adjudicatario::count()); // only real left (+ no other adj)
    }

    // ----------------------------------------------------------------
    // Case 2: Digit-tier match — AIRE NETWORKS example
    // ----------------------------------------------------------------

    public function test_digit_match_with_name_similarity_merges(): void
    {
        // Real: B53704599, nombre "AIRE NETWORKS DEL MEDITERRANEO S.L.U."
        $real = $this->adjudicatario('B53704599', 'AIRE NETWORKS DEL MEDITERRANEO S.L.U.', contratos: 10, importe: 200000);
        // Masked: "*** 7045 **" — visible digits "7045" — nombre variant
        $masked = $this->adjudicatario('*** 7045 **', 'AIRE NETWORKS DEL MEDITERRANEO SLU', contratos: 3, importe: 30000);
        $contrato = $this->contrato($masked->id);

        $this->artisan('nif:merge-masked')
            ->assertExitCode(0);

        // Masked deleted
        $this->assertNull(Adjudicatario::find($masked->id));
        // Contract reassigned
        $contrato->refresh();
        $this->assertSame($real->id, $contrato->adjudicatario_id);
    }

    // ----------------------------------------------------------------
    // Case 3: Digit match but totally different name → NO merge
    // ----------------------------------------------------------------

    public function test_digit_match_with_different_name_does_not_merge(): void
    {
        // Real: has "7045" in NIF but completely different name
        $real = $this->adjudicatario('B53704599', 'CONSTRUCTORA NORTE SA', contratos: 5, importe: 90000);
        $masked = $this->adjudicatario('*** 7045 **', 'EMPRESA COMPLETAMENTE DISTINTA SL', contratos: 2, importe: 10000);
        $contrato = $this->contrato($masked->id);

        $this->artisan('nif:merge-masked')
            ->assertExitCode(0);

        // Masked must still exist
        $this->assertNotNull(Adjudicatario::find($masked->id));
        // Contract still belongs to masked
        $contrato->refresh();
        $this->assertSame($masked->id, $contrato->adjudicatario_id);
    }

    // ----------------------------------------------------------------
    // Case 4: Two reals share the same visible digits → ambiguous, NO merge
    // ----------------------------------------------------------------

    public function test_ambiguous_digit_match_does_not_merge(): void
    {
        // Both reals contain "7045"
        $real1 = $this->adjudicatario('B53704599', 'EMPRESA ALFA SL', contratos: 5, importe: 50000);
        $real2 = $this->adjudicatario('A11704589', 'EMPRESA BETA SA', contratos: 3, importe: 30000);
        $masked = $this->adjudicatario('*** 7045 **', 'EMPRESA ALFA SL BETA', contratos: 1, importe: 5000);
        $contrato = $this->contrato($masked->id);

        $this->artisan('nif:merge-masked')
            ->assertExitCode(0);

        // Masked must survive — ambiguous
        $this->assertNotNull(Adjudicatario::find($masked->id));
        $contrato->refresh();
        $this->assertSame($masked->id, $contrato->adjudicatario_id);
    }

    // ----------------------------------------------------------------
    // Case 5a: UTE with "||" → excluded
    // ----------------------------------------------------------------

    public function test_ute_is_never_merged(): void
    {
        $real = $this->adjudicatario('B12345678', 'EMPRESA UNO SL', contratos: 5, importe: 50000);
        // UTE: masked NIF, name contains "||"
        $masked = $this->adjudicatario('*****1234**', 'EMPRESA UNO SL || EMPRESA DOS SA', contratos: 2, importe: 20000);
        $contrato = $this->contrato($masked->id);

        $this->artisan('nif:merge-masked')
            ->assertExitCode(0);

        // UTE must survive
        $this->assertNotNull(Adjudicatario::find($masked->id));
        $contrato->refresh();
        $this->assertSame($masked->id, $contrato->adjudicatario_id);
    }

    // ----------------------------------------------------------------
    // Case 5b: Individual (masked DNI ending with letter) → excluded
    // ----------------------------------------------------------------

    public function test_individual_masked_dni_is_never_merged(): void
    {
        // Real company that might match by name
        $real = $this->adjudicatario('B45000718', 'GARCIA LOPEZ EMPRESA SL', contratos: 3, importe: 15000);
        // Individual: masked DNI *****718M (ends with M)
        $masked = $this->adjudicatario('*****718M', 'GARCIA LOPEZ EMPRESA SL', contratos: 1, importe: 5000);
        $contrato = $this->contrato($masked->id);

        $this->artisan('nif:merge-masked')
            ->assertExitCode(0);

        // Individual must NOT be merged
        $this->assertNotNull(Adjudicatario::find($masked->id));
        $contrato->refresh();
        $this->assertSame($masked->id, $contrato->adjudicatario_id);
    }

    // ----------------------------------------------------------------
    // Case 6: Full merge — contracts reassigned and masked deleted
    // ----------------------------------------------------------------

    public function test_full_merge_reassigns_all_contracts_and_deletes_masked(): void
    {
        $real = $this->adjudicatario('B99887766', 'Limpiezas Rápidas, S.L.', contratos: 0, importe: 0);
        $masked = $this->adjudicatario('***  ***', 'Limpiezas Rápidas, S.L.', contratos: 3, importe: 15000);

        $c1 = $this->contrato($masked->id);
        $c2 = $this->contrato($masked->id);
        $c3 = $this->contrato($masked->id);

        // Run without --dry-run
        $this->artisan('nif:merge-masked')
            ->assertExitCode(0);

        // All contracts reassigned
        foreach ([$c1, $c2, $c3] as $c) {
            $c->refresh();
            $this->assertSame($real->id, $c->adjudicatario_id, "Contract {$c->id} should belong to real");
        }

        // Masked deleted
        $this->assertNull(Adjudicatario::find($masked->id), 'Masked adjudicatario should be deleted after merge');

        // Real still exists
        $this->assertNotNull(Adjudicatario::find($real->id), 'Real adjudicatario should still exist');
    }

    // ----------------------------------------------------------------
    // Case 7: HARDENING — same digits + shared GENERIC prefix but different
    // full surnames → NO merge. (The old matcher merged these via a 4-char
    // prefix anchor, the main source of false positives that disabled the
    // scheduler. Now requires full-token "apellidos completos" match.)
    // ----------------------------------------------------------------

    public function test_shared_prefix_but_different_full_name_does_not_merge(): void
    {
        // Both have "7045" in the NIF and start with "CONSTRUCCIONES", but the
        // distinctive surname differs → two different firms, must NOT merge.
        $real = $this->adjudicatario('B53704599', 'CONSTRUCCIONES GARCIA SL', contratos: 5, importe: 50000);
        $masked = $this->adjudicatario('*** 7045 **', 'CONSTRUCCIONES LOPEZ SL', contratos: 2, importe: 10000);
        $contrato = $this->contrato($masked->id);

        $this->artisan('nif:merge-masked')->assertExitCode(0);

        $this->assertNotNull(Adjudicatario::find($masked->id), 'Distinct firms sharing a prefix must NOT merge');
        $contrato->refresh();
        $this->assertSame($masked->id, $contrato->adjudicatario_id);
    }

    // ----------------------------------------------------------------
    // Case 8: HARDENING — a single shared generic token is not enough.
    // ----------------------------------------------------------------

    public function test_single_shared_generic_token_does_not_merge(): void
    {
        // Masked reduces to one token {gestion}; real has more tokens. A lone
        // generic word must not glue them even with a digit match.
        $real = $this->adjudicatario('B53704599', 'GESTION INTEGRAL DE RESIDUOS SL', contratos: 4, importe: 40000);
        $masked = $this->adjudicatario('*** 7045 **', 'GESTION SL', contratos: 1, importe: 5000);
        $contrato = $this->contrato($masked->id);

        $this->artisan('nif:merge-masked')->assertExitCode(0);

        $this->assertNotNull(Adjudicatario::find($masked->id), 'A single generic token must NOT trigger a merge');
        $contrato->refresh();
        $this->assertSame($masked->id, $contrato->adjudicatario_id);
    }

    // ----------------------------------------------------------------
    // Case 9: HARDENING — real is a person's DNI (not a company CIF) →
    // NO merge, even with exact name + matching digits. Individuals must
    // stay separate (PLACSP privacy / out of scope).
    // ----------------------------------------------------------------

    public function test_person_dni_real_is_never_a_merge_target(): void
    {
        // Real "31678950S" is a DNI (person), exact same name, digits 7895 present.
        $real = $this->adjudicatario('31678950S', 'MIGUEL ANGEL GONZALEZ ROMAN', contratos: 5, importe: 50000);
        $masked = $this->adjudicatario('***7895**', 'MIGUEL ANGEL GONZALEZ ROMAN', contratos: 2, importe: 8000);
        $contrato = $this->contrato($masked->id);

        $this->artisan('nif:merge-masked')->assertExitCode(0);

        $this->assertNotNull(Adjudicatario::find($masked->id), 'A person DNI must never be a merge target');
        $contrato->refresh();
        $this->assertSame($masked->id, $contrato->adjudicatario_id);
    }

    // ----------------------------------------------------------------
    // Unit tests for helper methods
    // ----------------------------------------------------------------

    public function test_is_company_nif_distinguishes_cif_from_persons(): void
    {
        $cmd = new MergeMaskedAdjudicatarios;

        // Company CIFs
        $this->assertTrue($cmd->isCompanyNif('B53704599'));
        $this->assertTrue($cmd->isCompanyNif('A11704589'));
        $this->assertTrue($cmd->isCompanyNif('Q2800001A'));

        // Persons / invalid → false
        $this->assertFalse($cmd->isCompanyNif('31678950S'));  // DNI
        $this->assertFalse($cmd->isCompanyNif('Y5237548W'));  // NIE
        $this->assertFalse($cmd->isCompanyNif('XXX2667XX'));  // anonymised
        $this->assertFalse($cmd->isCompanyNif('***7895**'));  // masked
    }

    public function test_names_are_similar_requires_full_tokens(): void
    {
        $cmd = new MergeMaskedAdjudicatarios;

        // Same two distinctive tokens (different legal form / accents) → similar.
        $this->assertTrue($cmd->namesAreSimilar(
            'AIRE NETWORKS DEL MEDITERRÁNEO S.L.U.',
            'Aire Networks del Mediterraneo, SL'
        ));

        // Shared generic prefix token, different surname → NOT similar.
        $this->assertFalse($cmd->namesAreSimilar('CONSTRUCCIONES GARCIA SL', 'CONSTRUCCIONES LOPEZ SL'));

        // A single shared generic token → NOT similar.
        $this->assertFalse($cmd->namesAreSimilar('GESTION SL', 'GESTION INTEGRAL DE RESIDUOS SL'));

        // Only legal-form/noise tokens remain → NOT similar.
        $this->assertFalse($cmd->namesAreSimilar('SL', 'SA'));
    }

    public function test_significant_tokens_drops_legal_forms_and_short_words(): void
    {
        $cmd = new MergeMaskedAdjudicatarios;

        $this->assertSame(
            ['aire', 'networks', 'mediterraneo'],
            $cmd->significantTokens('AIRE NETWORKS DEL MEDITERRÁNEO, S.L.U.')
        );
    }

    public function test_extract_visible_digits_returns_null_below_threshold(): void
    {
        $cmd = new MergeMaskedAdjudicatarios;
        $this->assertNull($cmd->extractVisibleDigits('***'));
        $this->assertNull($cmd->extractVisibleDigits('*****'));
        $this->assertNull($cmd->extractVisibleDigits('***1**'));   // only 1 digit
        $this->assertNull($cmd->extractVisibleDigits('***12**'));  // only 2 digits
        $this->assertNull($cmd->extractVisibleDigits('*****718M')); // letter at end → 3 digits only
    }

    public function test_extract_visible_digits_returns_correct_sequence(): void
    {
        $cmd = new MergeMaskedAdjudicatarios;
        $this->assertSame('7045', $cmd->extractVisibleDigits('*** 7045 **'));
        $this->assertSame('4329', $cmd->extractVisibleDigits('*****4329**'));
        $this->assertSame('12345', $cmd->extractVisibleDigits('*****12345*'));
    }

    public function test_names_are_similar_substring(): void
    {
        $cmd = new MergeMaskedAdjudicatarios;
        // Normalized: "airenetworksdelmediterraneosl" contains "airenetworks..."
        $normReal = $cmd->normalize('AIRE NETWORKS DEL MEDITERRANEO S.L.U.');
        $normMasked = $cmd->normalize('AIRE NETWORKS DEL MEDITERRANEO SLU');
        $this->assertTrue($cmd->namesAreSimilar($normReal, $normMasked));
    }

    public function test_names_are_similar_returns_false_for_different_names(): void
    {
        $cmd = new MergeMaskedAdjudicatarios;
        $normA = $cmd->normalize('CONSTRUCTORA NORTE SA');
        $normB = $cmd->normalize('EMPRESA COMPLETAMENTE DISTINTA SL');
        $this->assertFalse($cmd->namesAreSimilar($normA, $normB));
    }

    public function test_normalize_strips_legal_form_variants(): void
    {
        $cmd = new MergeMaskedAdjudicatarios;
        $this->assertSame(
            $cmd->normalize('EMPRESA SL'),
            $cmd->normalize('EMPRESA, S.L.U.')  // SLU → SL after strip
        );
    }

    // ----------------------------------------------------------------
    // Dry-run smoke test
    // ----------------------------------------------------------------

    public function test_dry_run_does_not_modify_data(): void
    {
        $real = $this->adjudicatario('B12300001', 'Test Empresa SL', contratos: 5, importe: 50000);
        $masked = $this->adjudicatario('***  ***', 'Test Empresa SL', contratos: 2, importe: 5000);
        $contrato = $this->contrato($masked->id);

        $this->artisan('nif:merge-masked', ['--dry-run' => true])
            ->expectsOutputToContain('DRY-RUN — Fusionables: 1')
            ->assertExitCode(0);

        // Nothing changed
        $this->assertNotNull(Adjudicatario::find($masked->id));
        $contrato->refresh();
        $this->assertSame($masked->id, $contrato->adjudicatario_id);
    }
}
