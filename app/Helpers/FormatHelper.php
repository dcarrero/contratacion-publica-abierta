<?php

declare(strict_types=1);

if (! function_exists('formatImporte')) {
    function formatImporte(?float $amount): string
    {
        if ($amount === null) {
            return '—';
        }

        return number_format($amount, 2, ',', '.').' €';
    }
}

if (! function_exists('formatImporteCorto')) {
    /**
     * Importe abreviado con una sola unidad grande: millones.
     *
     * Antes había tres escalones (K€, M€, Md€) y en una misma tabla convivían "5,7 M€" y
     * "11,3 Md€", que se diferencian en un carácter y en un factor de mil. Ahora todo lo
     * que llega al millón se expresa en millones —"11.300 M€"— y por debajo se dan los
     * euros completos, que a esas magnitudes se leen sin esfuerzo.
     */
    function formatImporteCorto(?float $amount): string
    {
        if ($amount === null) {
            return '—';
        }

        if (abs($amount) >= 1_000_000_000) {
            // A partir de mil millones, los decimales sobran: "11.300 M€".
            return number_format($amount / 1_000_000, 0, ',', '.').' M€';
        }

        if (abs($amount) >= 1_000_000) {
            return number_format($amount / 1_000_000, 1, ',', '.').' M€';
        }

        return number_format($amount, 0, ',', '.').' €';
    }
}

if (! function_exists('formatFecha')) {
    function formatFecha(mixed $date): string
    {
        if ($date === null) {
            return '—';
        }

        if ($date instanceof \DateTimeInterface) {
            return $date->format('d/m/Y');
        }

        return \Carbon\Carbon::parse($date)->format('d/m/Y');
    }
}
