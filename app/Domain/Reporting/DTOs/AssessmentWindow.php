<?php

declare(strict_types=1);

namespace App\Domain\Reporting\DTOs;

/** One round of the assessment window table (Monitor §9.2.4); visit dates are local days. */
final readonly class AssessmentWindow
{
    public function __construct(
        public int $roundId,
        public string $label,
        public string $windowStart,
        public string $windowEnd,
        public ?string $firstVisit,
        public ?string $lastVisit,
        public int $assessments,
    ) {}
}
