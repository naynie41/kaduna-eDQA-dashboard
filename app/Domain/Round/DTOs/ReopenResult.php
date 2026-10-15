<?php

declare(strict_types=1);

namespace App\Domain\Round\DTOs;

use App\Domain\Round\Models\Round;
use App\Domain\Scoring\Models\RescoreRun;

/** A reopened round, and the rescore started with it, if any. */
final readonly class ReopenResult
{
    public function __construct(
        public Round $round,
        public ?RescoreRun $rescore,
    ) {}
}
