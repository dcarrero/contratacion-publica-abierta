<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Abstrae diferencias SQL entre SQLite, MariaDB/MySQL y PostgreSQL.
 * Centraliza la lógica condicional para evitar repetirla en cada archivo.
 */
final class SqlDialect
{
    private static ?string $driver = null;

    public static function driver(): string
    {
        return self::$driver ??= DB::getDriverName();
    }

    public static function isPgsql(): bool
    {
        return self::driver() === 'pgsql';
    }

    public static function isSqlite(): bool
    {
        return self::driver() === 'sqlite';
    }

    /**
     * YEAR(col) — extrae el año como texto (para display/groupBy con strings).
     */
    public static function year(string $col): string
    {
        return match (self::driver()) {
            'sqlite' => "strftime(\"%Y\", {$col})",
            'pgsql' => "EXTRACT(YEAR FROM {$col})::TEXT",
            default => "YEAR({$col})",
        };
    }

    /**
     * YEAR(col) como número — para comparar con IN (2024, 2025).
     */
    public static function yearInt(string $col): string
    {
        return match (self::driver()) {
            'sqlite' => "CAST(strftime(\"%Y\", {$col}) AS INTEGER)",
            'pgsql' => "EXTRACT(YEAR FROM {$col})::INTEGER",
            default => "YEAR({$col})",
        };
    }

    /**
     * Formato YYYY-MM para agrupación mensual.
     */
    public static function yearMonth(string $col): string
    {
        return match (self::driver()) {
            'sqlite' => "strftime(\"%Y-%m\", {$col})",
            'pgsql' => "TO_CHAR({$col}, 'YYYY-MM')",
            default => "DATE_FORMAT({$col}, \"%Y-%m\")",
        };
    }

    /**
     * SUBSTR/LEFT — extrae primeros N caracteres.
     * LEFT() funciona en MariaDB y PostgreSQL; SUBSTR en SQLite.
     */
    public static function left(string $col, int $length): string
    {
        return match (self::driver()) {
            'sqlite' => "SUBSTR({$col}, 1, {$length})",
            default => "LEFT({$col}, {$length})",
        };
    }

    /**
     * GROUP_CONCAT(col) — concatena valores agrupados.
     */
    public static function groupConcat(string $col): string
    {
        return match (self::driver()) {
            'pgsql' => "STRING_AGG({$col}::TEXT, ',')",
            'sqlite' => "GROUP_CONCAT({$col})",
            default => "GROUP_CONCAT({$col})",
        };
    }

    /**
     * Resta de fecha: fecha >= NOW() - N días/meses.
     */
    public static function dateSubFilter(string $col, int $n, string $unit = 'days'): string
    {
        return match (self::driver()) {
            'sqlite' => "{$col} >= date('now', '-{$n} {$unit}')",
            'pgsql' => "{$col} >= CURRENT_DATE - INTERVAL '{$n} {$unit}'",
            default => "{$col} >= DATE_SUB(NOW(), INTERVAL {$n} ".strtoupper(rtrim($unit, 's')).')',
        };
    }

    /**
     * Inicio del mes actual como filtro.
     */
    public static function startOfMonth(): string
    {
        return match (self::driver()) {
            'sqlite' => "date('now', 'start of month')",
            'pgsql' => "DATE_TRUNC('month', NOW())",
            default => "DATE_FORMAT(NOW(), '%Y-%m-01')",
        };
    }

    /**
     * Mes actual en formato YYYY-MM.
     */
    public static function currentYearMonth(): string
    {
        return match (self::driver()) {
            'sqlite' => "strftime('%Y-%m', 'now')",
            'pgsql' => "TO_CHAR(NOW(), 'YYYY-MM')",
            default => "DATE_FORMAT(NOW(), '%Y-%m')",
        };
    }

    /**
     * Concatenación de strings: || en SQLite/PG, CONCAT en MariaDB.
     */
    public static function concat(string ...$parts): string
    {
        if (self::driver() === 'mysql' || self::driver() === 'mariadb') {
            return 'CONCAT('.implode(', ', $parts).')';
        }

        // SQLite y PostgreSQL usan ||
        return implode(' || ', $parts);
    }

    /**
     * Expresión CASE WHEN para contar booleanos en raw SQL.
     * PG: CASE WHEN col THEN 1 ELSE 0 END
     * MariaDB/SQLite: CASE WHEN col = 1 THEN 1 ELSE 0 END
     */
    public static function sumBool(string $col): string
    {
        if (self::isPgsql()) {
            return "SUM(CASE WHEN {$col} THEN 1 ELSE 0 END)";
        }

        return "SUM(CASE WHEN {$col} = 1 THEN 1 ELSE 0 END)";
    }

    /**
     * Valor booleano TRUE para usar en raw SQL WHERE.
     */
    public static function true(): string
    {
        return self::isPgsql() ? 'TRUE' : '1';
    }

    /**
     * LIKE case-insensitive: ILIKE en PG, LIKE en otros (ya case-insensitive).
     */
    public static function ilike(): string
    {
        return self::isPgsql() ? 'ILIKE' : 'LIKE';
    }

    /**
     * Reset del driver cacheado (útil para tests).
     */
    public static function reset(): void
    {
        self::$driver = null;
    }
}
