<?php

declare(strict_types=1);

namespace App\Domain\Reporting\DTOs;

use App\Domain\Scoring\DTOs\ScoreValue;

/** One ward and its overall: Home's "Weakest wards in {LGA}" list. */
final readonly class WardScore
{
    public function __construct(
        public int $wardId,
        public string $name,
        public int $facilities,
        public ScoreValue $overall,
    ) {}
}
