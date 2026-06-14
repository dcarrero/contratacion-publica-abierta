<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Adjudicatario;
use App\Models\AdjudicatarioAlias;
use App\Models\Contrato;
use App\Models\Organismo;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class NormalizeNifs extends Command
{
    protected $signature = 'nif:normalize
        {--dry-run : Mostrar cambios sin aplicarlos}';

    protected $description = 'Normaliza NIFs a mayúsculas y fusiona adjudicatarios duplicados';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('=== MODO DRY-RUN — No se aplicarán cambios ===');
        }

        $this->normalizeOrganismos($dryRun);
        $this->normalizeAdjudicatarios($dryRun);
        $this->normalizeContratosNifs($dryRun);

        $this->newLine();
        $this->info('Normalización completada.');

        return self::SUCCESS;
    }

    private function normalizeOrganismos(bool $dryRun): void
    {
        $this->info('');
        $this->info('--- Organismos ---');

        $affected = Organismo::whereColumn(DB::raw('UPPER(nif)'), '!=', 'nif')->count();
        $this->line("  NIFs en minúsculas: {$affected}");

        if ($affected > 0 && ! $dryRun) {
            Organismo::whereColumn(DB::raw('UPPER(nif)'), '!=', 'nif')
                ->update(['nif' => DB::raw('UPPER(nif)')]);
            $this->info("  Corregidos: {$affected}");
        }
    }

    private function normalizeAdjudicatarios(bool $dryRun): void
    {
        $this->info('');
        $this->info('--- Adjudicatarios ---');

        // 1. Encontrar duplicados (mismo NIF en distinta capitalización)
        $duplicates = DB::select('
            SELECT UPPER(nif) as nif_upper, COUNT(*) as cnt
            FROM adjudicatarios
            GROUP BY UPPER(nif)
            HAVING COUNT(*) > 1
        ');

        $this->line('  NIFs duplicados por capitalización: '.count($duplicates));

        $mergedCount = 0;
        $contractsReassigned = 0;
        $aliasesMerged = 0;

        foreach ($duplicates as $dupe) {
            $records = Adjudicatario::whereRaw('UPPER(nif) = ?', [$dupe->nif_upper])
                ->orderByDesc('total_contratos')
                ->get();

            // El "ganador" es el que tiene más contratos
            $winner = $records->first();
            $losers = $records->slice(1);

            if ($dryRun) {
                $this->line("  Merge: {$dupe->nif_upper} — mantener #{$winner->id} ({$winner->nombre}), fusionar ".$losers->count());

                continue;
            }

            DB::transaction(function () use ($winner, $losers, $dupe, &$contractsReassigned, &$aliasesMerged, &$mergedCount) {
                foreach ($losers as $loser) {
                    // Reasignar contratos
                    $moved = Contrato::where('adjudicatario_id', $loser->id)
                        ->update(['adjudicatario_id' => $winner->id]);
                    $contractsReassigned += $moved;

                    // Fusionar aliases: mover los del perdedor al ganador
                    $loserAliases = AdjudicatarioAlias::where('adjudicatario_id', $loser->id)->get();
                    foreach ($loserAliases as $alias) {
                        $existing = AdjudicatarioAlias::where('adjudicatario_id', $winner->id)
                            ->where('nombre_variante', $alias->nombre_variante)
                            ->first();

                        if ($existing) {
                            $existing->update([
                                'veces_visto' => $existing->veces_visto + $alias->veces_visto,
                                'primera_vez' => min($existing->primera_vez, $alias->primera_vez),
                                'ultima_vez' => max($existing->ultima_vez, $alias->ultima_vez),
                            ]);
                            $alias->delete();
                        } else {
                            $alias->update(['adjudicatario_id' => $winner->id]);
                        }
                        $aliasesMerged++;
                    }

                    // Registrar el nombre del perdedor como alias si no existe
                    $loserNameExists = AdjudicatarioAlias::where('adjudicatario_id', $winner->id)
                        ->where('nombre_variante', $loser->nombre)
                        ->exists();

                    if (! $loserNameExists && $loser->nombre !== $winner->nombre) {
                        AdjudicatarioAlias::create([
                            'adjudicatario_id' => $winner->id,
                            'nombre_variante' => $loser->nombre,
                            'veces_visto' => $loser->total_contratos ?: 1,
                            'primera_vez' => $loser->created_at,
                            'ultima_vez' => $loser->updated_at,
                        ]);
                    }

                    // Eliminar el perdedor
                    $loser->delete();
                    $mergedCount++;
                }

                // Actualizar NIF del ganador DESPUÉS de eliminar perdedores
                if ($winner->nif !== $dupe->nif_upper) {
                    $winner->update(['nif' => $dupe->nif_upper]);
                }
            });
        }

        if (! $dryRun) {
            $this->info("  Adjudicatarios fusionados: {$mergedCount}");
            $this->info("  Contratos reasignados: {$contractsReassigned}");
            $this->info("  Aliases procesados: {$aliasesMerged}");
        }

        // 2. Uppercase los NIFs restantes que no son duplicados
        $remaining = Adjudicatario::whereColumn(DB::raw('UPPER(nif)'), '!=', 'nif')->count();
        $this->line("  NIFs restantes en minúsculas: {$remaining}");

        if ($remaining > 0 && ! $dryRun) {
            Adjudicatario::whereColumn(DB::raw('UPPER(nif)'), '!=', 'nif')
                ->update(['nif' => DB::raw('UPPER(nif)')]);
            $this->info("  Corregidos: {$remaining}");
        }
    }

    private function normalizeContratosNifs(bool $dryRun): void
    {
        $this->info('');
        $this->info('--- Contratos (nif_organo / nif_adjudicatario) ---');

        $organos = Contrato::whereNotNull('nif_organo')
            ->whereColumn(DB::raw('UPPER(nif_organo)'), '!=', 'nif_organo')
            ->count();

        $adjudicatarios = Contrato::whereNotNull('nif_adjudicatario')
            ->whereColumn(DB::raw('UPPER(nif_adjudicatario)'), '!=', 'nif_adjudicatario')
            ->count();

        $this->line("  nif_organo en minúsculas: {$organos}");
        $this->line("  nif_adjudicatario en minúsculas: {$adjudicatarios}");

        if (! $dryRun) {
            if ($organos > 0) {
                Contrato::whereNotNull('nif_organo')
                    ->whereColumn(DB::raw('UPPER(nif_organo)'), '!=', 'nif_organo')
                    ->update(['nif_organo' => DB::raw('UPPER(nif_organo)')]);
            }
            if ($adjudicatarios > 0) {
                Contrato::whereNotNull('nif_adjudicatario')
                    ->whereColumn(DB::raw('UPPER(nif_adjudicatario)'), '!=', 'nif_adjudicatario')
                    ->update(['nif_adjudicatario' => DB::raw('UPPER(nif_adjudicatario)')]);
            }
            $this->info("  Corregidos: {$organos} organo + {$adjudicatarios} adjudicatario");
        }
    }
}
