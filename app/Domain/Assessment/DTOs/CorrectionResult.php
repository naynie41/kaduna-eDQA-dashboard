<?php

declare(strict_types=1);

namespace App\Domain\Assessment\DTOs;

use App\Domain\Assessment\Models\Assessment;
use App\Domain\Round\Models\Round;

/** What CorrectAssessment did: the assessment as it now is, each changed item, the new times. */
final readonly class CorrectionResult
{
    /**
     * @param  list<array{key: string, old: string, new: string}>  $changes  results (pass, fail, na)
     * @param  array{started_at: string, ended_at: string}|null  $times  null when unchanged
     */
    public function __construct(
        public Assessment $assessment,
        public Round $round,
        public array $changes,
        public ?array $times,
    ) {}
}
