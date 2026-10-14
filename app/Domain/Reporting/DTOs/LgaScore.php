<?php

declare(strict_types=1);

namespace App\Domain\Reporting\DTOs;

use App\Domain\Scoring\DTOs\ScoreValue;

/** One of the weakest LGAs (Home §9.2.1). */
final readonly class LgaScore
{
    public function __construct(
        public int $lgaId,
        public string $code,
        public string $name,
        public int $facilities,
        public ScoreValue $overall,
    ) {}
}
