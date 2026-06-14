<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Adjudicatario;
use App\Models\Contrato;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MergeMaskedAdjudicatarios extends Command
{
    protected $signature = 'nif:merge-masked {--dry-run : Solo mostrar cambios sin aplicar}';

    protected $description = 'Fusiona adjudicatarios con NIF enmascarado (***) con su equivalente real por nombre o dígitos visibles';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        $this->info('Buscando adjudicatarios enmascarados fusionables...');

        $merged = 0;
        $contratosReasignados = 0;
        $importeReasignado = 0.0;
        $aliasesCreados = 0;
        $ambiguos = 0;

        // Process masked in chunks — never load all reales into memory
        Adjudicatario::where('nif', 'LIKE', '%*%')
            ->where('total_contratos', '>', 0)
            ->select('id', 'nif', 'nombre', 'nombre_normalizado', 'total_contratos', 'total_importe')
            ->chunk(500, function ($chunk) use (
                $dryRun,
                &$merged,
                &$contratosReasignados,
                &$importeReasignado,
                &$aliasesCreados,
                &$ambiguos
            ) {
                foreach ($chunk as $m) {
                    // --- Safety guards ---
                    // Exclude UTEs (names joined by "||")
                    if (str_contains($m->nombre, '||')) {
                        continue;
                    }
                    // Exclude individuals: masked DNI ends with a letter, e.g. *****718M
                    if ($this->isIndividual($m->nif)) {
                        continue;
                    }

                    $real = $this->findReal($m, $ambiguos);

                    if ($real === null) {
                        continue;
                    }

                    if ($dryRun) {
                        if ($merged < 20) {
                            $this->line("  {$m->nif} | {$m->nombre} ({$m->total_contratos} contr) => {$real->nif} | {$real->nombre}");
                        }
                        $merged++;
                        $contratosReasignados += $m->total_contratos;
                        $importeReasignado += (float) $m->total_importe;

                        continue;
                    }

                    DB::transaction(function () use ($m, $real, &$contratosReasignados, &$aliasesCreados) {
                        // Reassign contracts
                        $updated = Contrato::where('adjudicatario_id', $m->id)
                            ->update(['adjudicatario_id' => $real->id]);
                        $contratosReasignados += $updated;

                        // Create alias if name differs
                        if (mb_strtolower($m->nombre) !== mb_strtolower($real->nombre)) {
                            DB::table('adjudicatario_aliases')->insertOrIgnore([
                                'adjudicatario_id' => $real->id,
                                'nombre_variante' => $m->nombre,
                                'veces_visto' => $m->total_contratos,
                                'primera_vez' => now(),
                                'ultima_vez' => now(),
                            ]);
                            $aliasesCreados++;
                        }

                        // Move aliases that don't conflict, delete the rest
                        $existingAliases = DB::table('adjudicatario_aliases')
                            ->where('adjudicatario_id', $real->id)
                            ->pluck('nombre_variante')
                            ->map(fn ($n) => mb_strtolower($n))
                            ->toArray();

                        DB::table('adjudicatario_aliases')
                            ->where('adjudicatario_id', $m->id)
                            ->get()
                            ->each(function ($alias) use ($real, $existingAliases) {
                                if (in_array(mb_strtolower($alias->nombre_variante), $existingAliases)) {
                                    DB::table('adjudicatario_aliases')->where('id', $alias->id)->delete();
                                } else {
                                    DB::table('adjudicatario_aliases')->where('id', $alias->id)
                                        ->update(['adjudicatario_id' => $real->id]);
                                }
                            });

                        $m->delete();
                    });

                    $merged++;
                    $importeReasignado += (float) $m->total_importe;
                }
            });

        if ($dryRun) {
            $this->newLine();
            $this->info("DRY-RUN — Fusionables: {$merged}");
            $this->info("Contratos a reasignar: {$contratosReasignados}");
            $this->info('Importe a reasignar: '.number_format($importeReasignado, 2, ',', '.').' €');
            if ($ambiguos > 0) {
                $this->warn("Ambiguos omitidos (múltiples reales con mismos dígitos): {$ambiguos}");
            }
            $this->warn('Ejecuta sin --dry-run para aplicar.');
        } else {
            $this->info("Fusionados: {$merged} adjudicatarios");
            $this->info("Contratos reasignados: {$contratosReasignados}");
            $this->info("Aliases creados: {$aliasesCreados}");
            $this->info('Importe reasignado: '.number_format($importeReasignado, 2, ',', '.').' €');
            if ($ambiguos > 0) {
                $this->warn("Ambiguos omitidos: {$ambiguos}");
            }
            $this->warn('Ejecuta stats:recalculate para actualizar totales.');
        }

        return self::SUCCESS;
    }

    /**
     * Normalize a company name for matching.
     * Strips punctuation/spaces, lowercases, and collapses legal-form variants.
     */
    public function normalize(string $n): string
    {
        $n = mb_strtolower(str_replace(['.', ',', ' ', '-', '(', ')'], '', $n));
        // Normalize legal forms: SLU→SL, SAU→SA, SLNE→SL
        $n = (string) preg_replace('/slu$/', 'sl', $n);
        $n = (string) preg_replace('/sau$/', 'sa', $n);
        $n = (string) preg_replace('/slne$/', 'sl', $n);

        return $n;
    }

    /**
     * Returns true if the masked NIF looks like an individual (DNI pattern: ends with a letter).
     * e.g. *****718M or *****4329A
     */
    private function isIndividual(string $nif): bool
    {
        $stripped = preg_replace('/[\s*]/', '', $nif);

        // Ends with a letter → DNI/NIE pattern → individual
        return $stripped !== '' && ctype_alpha(substr($stripped, -1));
    }

    /**
     * Extract visible digits from a masked NIF.
     * e.g. "*** 7045 **" → "7045", "*****4329**" → "4329"
     * Returns null if fewer than 4 digits found.
     */
    public function extractVisibleDigits(string $nif): ?string
    {
        $digits = preg_replace('/[^0-9]/', '', $nif);

        return (mb_strlen($digits) >= 4) ? $digits : null;
    }

    /**
     * Determine if two normalized names share strong similarity.
     * Strategy: the shorter name must be a prefix/substring of the longer,
     * OR they share the same first significant token (≥4 chars).
     */
    public function namesAreSimilar(string $normA, string $normB): bool
    {
        if ($normA === '' || $normB === '') {
            return false;
        }

        // Substring check: shorter must be fully contained in longer
        $shorter = mb_strlen($normA) <= mb_strlen($normB) ? $normA : $normB;
        $longer = mb_strlen($normA) <= mb_strlen($normB) ? $normB : $normA;

        if (str_contains($longer, $shorter) && mb_strlen($shorter) >= 4) {
            return true;
        }

        // First significant token check (≥4 chars)
        $tokenA = $this->firstSignificantToken($normA);
        $tokenB = $this->firstSignificantToken($normB);

        return $tokenA !== null && $tokenB !== null && $tokenA === $tokenB;
    }

    /**
     * Return the first token of ≥4 characters from a normalized name.
     */
    private function firstSignificantToken(string $norm): ?string
    {
        // The normalized name has no spaces (stripped in normalize()), so treat every 4-char chunk
        // as a "token" is not feasible. We work on the original characters by treating the
        // normalized string itself: the first 4+ chars that form a contiguous run of letters/digits.
        if (mb_strlen($norm) < 4) {
            return null;
        }

        // Return first 4 characters as a minimal anchor
        return mb_substr($norm, 0, 4);
    }

    /**
     * Find a matching real adjudicatario for the given masked one.
     * Tier 1: exact normalized name match (SQL indexed).
     * Tier 2: visible digits of NIF + name similarity confirmation.
     * Returns null if no match or ambiguous.
     */
    private function findReal(Adjudicatario $masked, int &$ambiguos): ?Adjudicatario
    {
        $maskedNorm = $masked->nombre_normalizado ?? $this->normalize($masked->nombre);

        // --- Tier 1: exact normalized name match (DB query, no full-table load) ---
        if ($maskedNorm && mb_strlen($maskedNorm) > 3) {
            $real = Adjudicatario::where('nif', 'NOT LIKE', '%*%')
                ->where('nombre_normalizado', $maskedNorm)
                ->first();

            if ($real !== null) {
                return $real;
            }
        }

        // --- Tier 2: visible digits of NIF + name similarity ---
        $visibleDigits = $this->extractVisibleDigits($masked->nif);
        if ($visibleDigits === null) {
            return null;
        }

        $candidates = Adjudicatario::where('nif', 'NOT LIKE', '%*%')
            ->where('nif', 'LIKE', "%{$visibleDigits}%")
            ->select('id', 'nif', 'nombre', 'nombre_normalizado', 'total_contratos', 'total_importe')
            ->get();

        if ($candidates->isEmpty()) {
            return null;
        }

        // Filter by name similarity
        $matches = $candidates->filter(function ($candidate) use ($maskedNorm) {
            $candidateNorm = $candidate->nombre_normalizado ?? $this->normalize($candidate->nombre);

            return $this->namesAreSimilar($maskedNorm, $candidateNorm);
        });

        if ($matches->count() === 1) {
            return $matches->first();
        }

        if ($matches->count() > 1) {
            // Ambiguous — skip and report
            $ambiguos++;
        }

        return null;
    }
}
