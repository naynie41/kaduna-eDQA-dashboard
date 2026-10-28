<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\DTOs;

use App\Domain\Ingestion\Models\PullRun;

/** Where a backfill stands after a page (or when it stops): its run row and the checkpoint. */
final readonly class BackfillProgress
{
    public function __construct(
        public PullRun $run,
        public int $page,
        public int $position,
        public int $total,
        public bool $complete,
    ) {}
}
