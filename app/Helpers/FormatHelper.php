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
    function formatImporteCorto(?float $amount): string
    {
        if ($amount === null) {
            return '—';
        }

        if (abs($amount) >= 1_000_000_000) {
            return number_format($amount / 1_000_000_000, 1, ',', '.').' Md€';
        }

        if (abs($amount) >= 1_000_000) {
            return number_format($amount / 1_000_000, 1, ',', '.').' M€';
        }

        if (abs($amount) >= 1_000) {
            return number_format($amount / 1_000, 1, ',', '.').' K€';
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
