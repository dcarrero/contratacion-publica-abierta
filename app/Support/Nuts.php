<?php

declare(strict_types=1);

namespace App\Support;

class Nuts
{
    /**
     * Convierte un prefijo NUTS ('ES42', 'ES611' o 'ES611%') en límites de rango
     * [low, high) para filtrar con el índice btree. Un `LIKE 'prefijo%'` parametrizado
     * no usa el índice y acaba escaneando toda la tabla; `nuts >= low AND nuts < high`
     * sí lo usa y es equivalente para coincidencia por prefijo.
     *
     * @return array{0: string, 1: string}
     */
    public static function bounds(string $prefix): array
    {
        $low = rtrim($prefix, '%');

        if ($low === '') {
            // Sin prefijo real no hay acotación posible: devolver un rango vacío-seguro.
            return ['', ''];
        }

        $last = substr($low, -1);
        $high = substr($low, 0, -1).chr(ord($last) + 1);

        return [$low, $high];
    }
}
