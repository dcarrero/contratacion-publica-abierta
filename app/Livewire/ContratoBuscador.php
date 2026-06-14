<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\ComunidadAutonoma;
use App\Models\Contrato;
use App\Models\Provincia;
use App\Support\SqlDialect;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class ContratoBuscador extends Component
{
    use WithPagination;

    #[Url]
    public string $busqueda = '';

    #[Url]
    public string $tipo_contrato = '';

    #[Url]
    public string $procedimiento = '';

    #[Url]
    public string $estado = '';

    #[Url]
    public string $year = '';

    #[Url]
    public string $importe_min = '';

    #[Url]
    public string $importe_max = '';

    #[Url]
    public string $ccaa = '';

    #[Url]
    public string $provincia = '';

    #[Url]
    public string $orden = 'fecha_desc';

    public function updatedCcaa(): void
    {
        $this->provincia = '';
        $this->resetPage();
    }

    public function updated(): void
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset([
            'busqueda', 'tipo_contrato', 'procedimiento',
            'estado', 'year', 'importe_min', 'importe_max',
            'ccaa', 'provincia', 'orden',
        ]);
        $this->resetPage();
    }

    public function getFilterOptions(): array
    {
        return Cache::remember('buscador:filter_options', 86400, function () {
            $yearExpr = SqlDialect::year('fecha_publicacion');

            $years = Contrato::selectRaw("DISTINCT {$yearExpr} as year")
                ->whereNotNull('fecha_publicacion')
                ->orderByDesc('year')
                ->pluck('year')
                ->filter()
                ->values()
                ->toArray();

            $estados = array_keys(config('contratacion.estados', []));

            $ccaaOptions = ComunidadAutonoma::orderBy('nombre')
                ->pluck('nombre', 'nuts')
                ->toArray();

            $provinciasPorCcaa = Provincia::whereNotNull('nuts')
                ->orderBy('nombre')
                ->get()
                ->groupBy(function (Provincia $p) {
                    return $p->comunidadAutonoma->nuts ?? '';
                })
                ->map(fn ($g) => $g->pluck('nombre', 'nuts'))
                ->toArray();

            return [
                'tipos_contrato' => config('contratacion.tipos_contrato', []),
                'procedimientos' => config('contratacion.procedimientos', []),
                'estados' => $estados,
                'years' => $years,
                'ccaa' => $ccaaOptions,
                'provincias_por_ccaa' => $provinciasPorCcaa,
            ];
        });
    }

    public function hasActiveFilters(): bool
    {
        return $this->busqueda !== ''
            || $this->tipo_contrato !== ''
            || $this->procedimiento !== ''
            || $this->estado !== ''
            || $this->year !== ''
            || $this->importe_min !== ''
            || $this->importe_max !== ''
            || $this->ccaa !== ''
            || $this->provincia !== '';
    }

    public function render(): View
    {
        $query = Contrato::with(['organismo:id,nombre,nif', 'adjudicatario:id,nombre,nif']);

        if ($this->busqueda !== '') {
            $query->search($this->busqueda);
        }

        if ($this->tipo_contrato !== '') {
            $query->tipo($this->tipo_contrato);
        }

        if ($this->procedimiento !== '') {
            $query->procedimiento($this->procedimiento);
        }

        if ($this->estado !== '') {
            $query->estado($this->estado);
        }

        if ($this->year !== '') {
            $query->year((int) $this->year);
        }

        if ($this->importe_min !== '') {
            $query->where('importe_adjudicacion', '>=', (float) $this->importe_min);
        }

        if ($this->importe_max !== '') {
            $query->where('importe_adjudicacion', '<=', (float) $this->importe_max);
        }

        if ($this->provincia !== '') {
            $query->where('nuts', 'LIKE', "{$this->provincia}%");
        } elseif ($this->ccaa !== '') {
            $query->ccaa($this->ccaa);
        }

        $nullsLast = SqlDialect::isPgsql() ? ' NULLS LAST' : '';
        // Usar la mejor fecha disponible para ordenar (pub > adj > form)
        $fechaOrder = 'COALESCE(fecha_publicacion, fecha_adjudicacion, fecha_formalizacion)';

        $query = match ($this->orden) {
            'fecha_asc' => $query->orderByRaw("{$fechaOrder} ASC{$nullsLast}"),
            'importe_desc' => $query->orderByRaw("importe_adjudicacion DESC{$nullsLast}"),
            'importe_asc' => $query->orderByRaw("importe_adjudicacion ASC{$nullsLast}"),
            default => $query->orderByRaw("{$fechaOrder} DESC{$nullsLast}"),
        };

        $contratos = $query->paginate(10);
        $filterOptions = $this->getFilterOptions();

        return view('livewire.contrato-buscador', [
            'contratos' => $contratos,
            'filterOptions' => $filterOptions,
        ]);
    }
}
