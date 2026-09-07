@props([
    'valores' => [],    // 12 importes mensuales (enero-diciembre)
    'anio' => null,     // año al que corresponde la serie, para el texto alternativo
    'etiqueta' => null, // sobrescribe el aria-label calculado
    'ancho' => 90,
    'alto' => 24,
])
@php
    $meses = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
              'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    $valores = array_values(array_map(static fn ($v) => (float) $v, (array) $valores));
    $num = count($valores);
    $max = $num > 0 ? max($valores) : 0.0;

    // La base es el cero, no el mínimo de la serie: un mes sin adjudicaciones debe verse
    // en el suelo del gráfico y no a la misma altura que el peor mes con actividad.
    $base = $num > 0 ? min(0.0, min($valores)) : 0.0;
    $rango = $max - $base;

    $pad = 2;
    $ancho = (float) $ancho;
    $alto = (float) $alto;
    $util = $alto - 2 * $pad;

    $puntos = [];
    foreach ($valores as $i => $valor) {
        $x = $num > 1 ? $pad + $i * ($ancho - 2 * $pad) / ($num - 1) : $ancho / 2;
        $y = $rango > 0 ? $alto - $pad - ($valor - $base) / $rango * $util : $alto - $pad;
        $puntos[] = round($x, 2).','.round($y, 2);
    }

    $periodo = $anio !== null ? ' de '.$anio : '';
    if ($num === 0 || $rango <= 0) {
        $alt = 'Sin actividad mensual'.$periodo;
    } else {
        $indiceMax = array_keys($valores, $max, true)[0] ?? 0;
        $alt = 'Tendencia mensual'.$periodo.': máximo en '.($meses[$indiceMax] ?? 'el mes '.($indiceMax + 1));
    }
    $alt = $etiqueta ?? $alt;
@endphp
@if($num === 0)
    <span class="text-gray-300" aria-hidden="true">—</span>
    <span class="sr-only">{{ $alt }}</span>
@else
    <svg class="sparkline block" width="{{ $ancho }}" height="{{ $alto }}"
         viewBox="0 0 {{ $ancho }} {{ $alto }}" preserveAspectRatio="none"
         role="img" aria-label="{{ $alt }}">
        @if($num === 1)
            <circle cx="{{ $ancho / 2 }}" cy="{{ explode(',', $puntos[0])[1] }}" r="2" fill="#dc2626" />
        @else
            <polyline points="{{ implode(' ', $puntos) }}" fill="none" stroke="#dc2626" stroke-width="1.5"
                      stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" />
        @endif
    </svg>
@endif
