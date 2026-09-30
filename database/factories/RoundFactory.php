<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Round\Enums\RoundStatus;
use App\Domain\Round\Models\Round;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An open round whose window is its calendar quarter. Pass year and quarter to pick one.
 *
 * @extends Factory<Round>
 */
final class RoundFactory extends Factory
{
    protected $model = Round::class;

    public function definition(): array
    {
        return [
            // Unique (year, quarter) across a test run.
            'year' => fake()->unique()->numberBetween(2100, 9999),
            'quarter' => fake()->numberBetween(1, 4),
            'window_start' => fn (array $a): string => self::quarterStart($a)->toDateString(),
            'window_end' => fn (array $a): string => self::quarterStart($a)->addMonths(3)->subDay()->toDateString(),
            'status' => RoundStatus::Open,
        ];
    }

    public function closed(): self
    {
        return $this->state([
            'status' => RoundStatus::Closed,
            'closed_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private static function quarterStart(array $attributes): CarbonImmutable
    {
        return CarbonImmutable::create((int) $attributes['year'], 3 * (int) $attributes['quarter'] - 2, 1);
    }
}
