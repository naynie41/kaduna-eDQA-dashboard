<?php

declare(strict_types=1);

namespace App\Domain\Reporting\DTOs;

use App\Domain\Scoring\DTOs\ScoreValue;

/** One row of the LGA view (§9.2.3); the overall's band is the Status label. */
final readonly class LgaRow
{
    public function __construct(
        public int $lgaId,
        public string $code,
        public string $name,
        public int $facilities,
        public ScoreValue $availability,
        public ScoreValue $consistency,
        public ScoreValue $validity,
        public ScoreValue $m1,
        public ScoreValue $m2,
        public ScoreValue $m3,
        public ScoreValue $overall,
    ) {}
}
