<?php

declare(strict_types=1);

namespace App\Domain\Reporting\DTOs;

use App\Domain\Scoring\DTOs\ScoreValue;

/**
 * What publishing a draft would change in one open round: facilities and LGAs whose band moves,
 * and the state overall before and after. Every score is a ScoreValue: "before" under the
 * round's current version, "after" under the draft.
 */
final readonly class RoundPreview
{
    /**
     * @param  list<array{facility_id: int, code: string, lga_id: int, before: ScoreValue, after: ScoreValue}>  $facilitiesChangingBand
     * @param  list<array{lga_id: int, code: string, name: string, before: ScoreValue, after: ScoreValue}>  $lgasChangingBand
     */
    public function __construct(
        public int $roundId,
        public int $year,
        public int $quarter,
        public int $assessments,
        public int $skipped,
        public array $facilitiesChangingBand,
        public array $lgasChangingBand,
        public ScoreValue $stateOverallBefore,
        public ScoreValue $stateOverallAfter,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $scores = fn (array $row): array => [...$row, 'before' => $row['before']->toArray(), 'after' => $row['after']->toArray()];

        return [
            'round_id' => $this->roundId,
            'year' => $this->year,
            'quarter' => $this->quarter,
            'assessments' => $this->assessments,
            'skipped' => $this->skipped,
            'facilities_changing_band' => array_map($scores, $this->facilitiesChangingBand),
            'lgas_changing_band' => array_map($scores, $this->lgasChangingBand),
            'state_overall_before' => $this->stateOverallBefore->toArray(),
            'state_overall_after' => $this->stateOverallAfter->toArray(),
        ];
    }
}
