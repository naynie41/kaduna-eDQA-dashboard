<?php

declare(strict_types=1);

namespace App\Domain\Reporting\DTOs;

/** Public vs Private (§9.2.1, §9.2.4). */
final readonly class OwnerSplit
{
    public function __construct(
        public GroupScores $public,
        public GroupScores $private,
    ) {}
}
