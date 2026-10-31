<?php

declare(strict_types=1);

namespace App\Domain\Reporting\DTOs;

/** One source's accepted and flagged assessments in the selection (Monitor §9.2.4). */
final readonly class SourceCount
{
    public function __construct(
        public int $sourceId,
        public int $assessments,
    ) {}
}
