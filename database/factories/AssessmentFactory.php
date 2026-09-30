<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Assessment\Models\Assessment;
use App\Domain\Assessment\Models\AssessmentScore;
use App\Domain\Assessment\Models\ItemResponse;
use App\Domain\Facility\Models\Facility;
use App\Domain\Ingestion\Models\Submission;
use App\Domain\Round\Models\Round;
use App\Domain\Scoring\Enums\Dimension;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use InvalidArgumentException;

/**
 * An accepted assessment, visited inside its round's window.
 *
 * withScores() creates item responses that produce the requested scores; the scores
 * themselves are then derived from those items, never typed (CLAUDE.md hard rule 2).
 *
 * @extends Factory<Assessment>
 */
final class AssessmentFactory extends Factory
{
    protected $model = Assessment::class;

    public function definition(): array
    {
        return [
            'round_id' => Round::factory(),
            'facility_id' => Facility::factory(),
            'submission_id' => Submission::factory()->accepted(),
            'assessor_name' => fake()->name(),
            'device_id' => 'collect:'.fake()->bothify('????####'),
            'started_at' => fn (array $a): CarbonImmutable => self::visitStart((int) $a['round_id']),
            'ended_at' => fn (array $a): CarbonImmutable => CarbonImmutable::parse($a['started_at'])->addMinutes(75),
            'status' => 'accepted',
            'flags' => [],
        ];
    }

    public function forRound(Round $round): self
    {
        return $this->state(['round_id' => $round->id]);
    }

    /**
     * Target scores per dimension: one number for all three month slots, or three (one per
     * slot). null makes that slot all-N/A. Every dimension must be given.
     *
     * @param  array<string, float|int|null|array{0: float|int|null, 1: float|int|null, 2: float|int|null}>  $pattern
     */
    public function withScores(array $pattern): self
    {
        $cells = self::cells($pattern);

        return $this->afterCreating(function (Assessment $assessment) use ($cells): void {
            foreach ($cells as [$dimension, $slot, $split]) {
                self::createItems($assessment, $dimension, $slot, $split);
                AssessmentScore::factory()->create([
                    'assessment_id' => $assessment->id,
                    'dimension' => $dimension,
                    'month_slot' => $slot,
                ]);
            }
        });
    }

    /**
     * Validates the pattern up front, so an impossible score (e.g. 347.66) fails immediately.
     *
     * @param  array<string, mixed>  $pattern
     * @return list<array{0: Dimension, 1: int, 2: array{0: int, 1: int}|null}>
     */
    private static function cells(array $pattern): array
    {
        $cells = [];

        foreach (Dimension::cases() as $dimension) {
            if (! array_key_exists($dimension->value, $pattern)) {
                throw new InvalidArgumentException("withScores() needs a score for {$dimension->value}.");
            }
            $slots = is_array($pattern[$dimension->value]) ? array_values($pattern[$dimension->value]) : array_fill(0, 3, $pattern[$dimension->value]);
            if (count($slots) !== 3) {
                throw new InvalidArgumentException("{$dimension->value}: give one score or three.");
            }

            foreach ($slots as $i => $target) {
                $cells[] = [$dimension, $i + 1, $target === null ? null : self::split((float) $target)];
            }
        }

        return $cells;
    }

    /**
     * The smallest (passed, applicable) item counts whose ratio gives the target to 2 decimals.
     *
     * @return array{0: int, 1: int}
     */
    private static function split(float $target): array
    {
        if ($target < 0 || $target > 100) {
            throw new InvalidArgumentException("A score of {$target} cannot come from item pass counts.");
        }

        for ($applicable = 1; $applicable <= 100; $applicable++) {
            $passed = (int) round($target * $applicable / 100);
            if (abs($passed / $applicable * 100 - $target) < 0.005) {
                return [$passed, $applicable];
            }
        }

        throw new InvalidArgumentException("No split of up to 100 items gives a score of {$target}.");
    }

    /**
     * Passed items answer "yes", failed ones "no", and each slot also has one N/A item, which
     * is excluded from both counts. A null split makes the whole slot N/A.
     *
     * @param  array{0: int, 1: int}|null  $split
     */
    private static function createItems(Assessment $assessment, Dimension $dimension, int $slot, ?array $split): void
    {
        [$passed, $applicable] = $split ?? [0, 0];
        $answers = [
            ...array_fill(0, $passed, 'yes'),
            ...array_fill(0, $applicable - $passed, 'no'),
            'na',
            ...($split === null ? ['na'] : []),
        ];

        foreach ($answers as $i => $answer) {
            ItemResponse::factory()->create([
                'assessment_id' => $assessment->id,
                'dimension' => $dimension,
                'month_slot' => $slot,
                'item_code' => 'item_'.($i + 1),
                'value' => $answer,
                'is_applicable' => $answer !== 'na',
            ]);
        }
    }

    private static function visitStart(int $roundId): CarbonImmutable
    {
        $round = Round::query()->findOrFail($roundId, ['window_start', 'window_end']);
        $days = (int) min(20, $round->window_start->diffInDays($round->window_end));

        return $round->window_start->addDays(fake()->numberBetween(0, $days))->setTime(9, 30);
    }
}
