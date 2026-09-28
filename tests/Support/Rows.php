<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * Valid rows inserted with the raw query builder, for constraint tests that must bypass models
 * and factories. Each call gets unique names and codes; overrides replace any column.
 */
final class Rows
{
    private static int $sequence = 0;

    /** @param array<string, mixed> $overrides */
    public static function lga(array $overrides = []): int
    {
        $n = self::next();

        return DB::table('lgas')->insertGetId([
            'name' => "Test LGA {$n}",
            'code' => "LGA{$n}",
            ...$overrides,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    public static function ward(array $overrides = []): int
    {
        $n = self::next();

        return DB::table('wards')->insertGetId([
            'lga_id' => $overrides['lga_id'] ?? self::lga(),
            'name' => "Test Ward {$n}",
            'code' => "WRD{$n}",
            ...$overrides,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    public static function facility(array $overrides = []): int
    {
        $n = self::next();
        $lgaId = $overrides['lga_id'] ?? self::lga();

        return DB::table('facilities')->insertGetId([
            'code' => "FAC{$n}",
            'name' => "Test Facility {$n}",
            'lga_id' => $lgaId,
            'ward_id' => $overrides['ward_id'] ?? self::ward(['lga_id' => $lgaId]),
            'level' => 'primary',
            'ownership' => 'public',
            'created_at' => now(),
            'updated_at' => now(),
            ...$overrides,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    public static function round(array $overrides = []): int
    {
        $year = 2000 + self::next();

        return DB::table('rounds')->insertGetId([
            'year' => $year,
            'quarter' => 1,
            'window_start' => "{$year}-01-01",
            'window_end' => "{$year}-03-31",
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
            ...$overrides,
        ]);
    }

    private static function next(): int
    {
        return ++self::$sequence;
    }
}
