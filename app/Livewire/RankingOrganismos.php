<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\ComunidadAutonoma;
use App\Models\Organismo;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class RankingOrganismos extends Component
{
    use WithPagination;

    #[Url]
    public string $busqueda = '';

    #[Url]
    public string $ccaa = '';

    #[Url]
    public string $orden = 'importe_desc';

    public function updated(): void
    {
        $this->resetPage();
    }

    public function getCcaaOptions(): array
    {
        return Cache::remember('ranking:ccaa_options', 86400, function () {
            return ComunidadAutonoma::orderBy('nombre')
                ->pluck('nombre', 'nuts')
                ->toArray();
        });
    }

    public function render(): View
    {
        $query = Organismo::query()
            ->where('total_contratos', '>', 0);

        if (mb_strlen(trim($this->busqueda)) >= 2) {
            $query->search($this->busqueda);
        }

        if ($this->ccaa !== '') {
            $query->whereHas('contratos', fn ($q) => $q->ccaa($this->ccaa));
        }

        $query = match ($this->orden) {
            'contratos_desc' => $query->orderByDesc('total_contratos'),
            'contratos_asc' => $query->orderBy('total_contratos'),
            'importe_asc' => $query->orderBy('total_importe'),
            'nombre_asc' => $query->orderBy('nombre'),
            default => $query->orderByDesc('total_importe'),
        };

        return view('livewire.ranking-organismos', [
            'organismos' => $query->paginate(10),
            'ccaaOptions' => $this->getCcaaOptions(),
        ]);
    }
}
