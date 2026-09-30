<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Assessment\Models\Assessment;
use App\Domain\Assessment\Models\AssessmentScore;
use App\Domain\Assessment\Models\ItemResponse;
use App\Domain\Scoring\Enums\Dimension;
use App\Domain\Scoring\Models\ScoringRuleVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A score derived from the assessment's item responses for that dimension and month slot:
 * passed / applicable × 100, N/A excluded from both, null if nothing is applicable. There is
 * no way to type a score (CLAUDE.md hard rule 2); use AssessmentFactory::withScores() to get
 * items that produce a given one.
 *
 * @extends Factory<AssessmentScore>
 */
final class AssessmentScoreFactory extends Factory
{
    protected $model = AssessmentScore::class;

    public function definition(): array
    {
        return [
            'assessment_id' => Assessment::factory(),
            'dimension' => Dimension::Availability,
            'month_slot' => 1,
            'rule_version_id' => fn (): int => ScoringRuleVersion::query()
                ->whereNotNull('published_at')->orderByDesc('version')->value('id')
                ?? ScoringRuleVersion::factory()->published()->create()->id,
            'score' => fn (array $a): ?float => self::fromItems($a),
            'computed_at' => now(),
        ];
    }

    /** @param array<string, mixed> $attributes */
    private static function fromItems(array $attributes): ?float
    {
        $dimension = $attributes['dimension'] instanceof Dimension ? $attributes['dimension']->value : $attributes['dimension'];

        $items = ItemResponse::query()
            ->where('assessment_id', $attributes['assessment_id'])
            ->where('dimension', $dimension)
            ->where('month_slot', $attributes['month_slot'])
            ->where('is_applicable', true)
            ->pluck('value');

        return $items->isEmpty() ? null : round($items->filter(fn (string $v): bool => $v === 'yes')->count() / $items->count() * 100, 2);
    }
}
