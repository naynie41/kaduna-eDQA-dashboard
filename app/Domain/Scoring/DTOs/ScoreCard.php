<?php

declare(strict_types=1);

namespace App\Domain\Scoring\DTOs;

/**
 * The scores ScoreCalculator derives for one assessment, at full float precision (rounding
 * happens only at presentation, CONVENTION.md §2.7). Keys follow Dimension::cases() order and
 * months 1–3, so two cards from the same input are identical.
 *
 * exclusions lists each all-N/A slot as "SLOT_ALL_NA:{dimension}:{month}", the informational
 * note ARCHITECTURE.md §7 adds to assessments.flags.
 */
final readonly class ScoreCard
{
    /**
     * @param  array<string, array<int, ?float>>  $slots  dimension => month => score, null if all N/A
     * @param  array<string, float>  $dimensions
     * @param  list<string>  $exclusions
     */
    public function __construct(
        public array $slots,
        public array $dimensions,
        public float $overall,
        public array $exclusions,
    ) {}

    /** @return array{slots: array<string, array<int, ?float>>, dimensions: array<string, float>, overall: float, exclusions: list<string>} */
    public function toArray(): array
    {
        return [
            'slots' => $this->slots,
            'dimensions' => $this->dimensions,
            'overall' => $this->overall,
            'exclusions' => $this->exclusions,
        ];
    }
}
