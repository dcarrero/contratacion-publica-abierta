@props([
    'anioActual',
    'anioReferencia',   // último año con actividad
    'urlHistorico',
    'entidad' => 'Esta entidad',
])
{{-- Entidad sin contratos en el año en curso: es el caso mayoritario en la cola larga de
     adjudicatarios. En vez de una ficha vacía, se dice claramente y se manda al histórico. --}}
<div {{ $attributes->class('rounded-lg border border-amber-200 bg-amber-50 p-5') }}>
    <div class="flex flex-col sm:flex-row sm:items-center gap-4">
        <div class="flex-1">
            <p class="font-semibold text-amber-900">
                Sin actividad registrada en {{ $anioActual }}
            </p>
            <p class="mt-1 text-sm text-amber-800">
                {{ $entidad }} no tiene contratos publicados en {{ $anioActual }}.
                Los datos que se muestran corresponden a <strong>{{ $anioReferencia }}</strong>,
                su último año con actividad.
            </p>
        </div>
        <a href="{{ $urlHistorico }}"
           class="inline-flex items-center justify-center gap-2 px-4 py-2.5 bg-primary text-white rounded-lg hover:bg-primary-dark transition text-sm font-semibold whitespace-nowrap">
            Ver histórico completo
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
            </svg>
        </a>
    </div>
</div>
