<?php

declare(strict_types=1);

namespace App\Domain\Reporting\DTOs;

/**
 * The implementation plan (§9.2.6), read-only until Phase 6. round is the latest round in view
 * (the plan's round); threshold is the band whose lower bound an LGA must reach to need no action,
 * with that bound as shown. clear lists the LGAs at or above it, by name.
 */
final readonly class Plan
{
    /**
     * @param  array{band: string, label: string, value: string}|null  $threshold
     * @param  list<PlanRow>  $actions
     * @param  list<LgaScore>  $clear
     * @param  list<PlanSummary>  $summary
     */
    public function __construct(
        public ?string $round,
        public ?array $threshold,
        public array $actions,
        public array $clear,
        public array $summary,
    ) {}
}
