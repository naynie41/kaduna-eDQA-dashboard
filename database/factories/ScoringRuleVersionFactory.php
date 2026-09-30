<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Scoring\Models\ScoringRuleVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A draft rule version with the default bands (ARCHITECTURE.md §7). published() makes it
 * current, if its version number is the highest published.
 *
 * @extends Factory<ScoringRuleVersion>
 */
final class ScoringRuleVersionFactory extends Factory
{
    protected $model = ScoringRuleVersion::class;

    public function definition(): array
    {
        return [
            'version' => fn (): int => (int) ScoringRuleVersion::query()->max('version') + 1,
            'config' => [
                'bands' => ['strong' => 90, 'acceptable' => 80, 'review' => 70],
                'item_weights' => [],
                'na_policy' => 'exclude',
                'choice_map' => ['yes' => 'pass', 'no' => 'fail', 'na' => 'na'],
            ],
            'created_by' => User::factory(),
        ];
    }

    public function published(): self
    {
        return $this->state([
            'published_at' => now(),
            'published_reason' => 'Default scoring rules',
        ]);
    }
}
