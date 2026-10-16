<?php

declare(strict_types=1);

namespace App\Domain\Reporting\DTOs;

/**
 * Monitor coverage (§9.2.4): facilities visited per LGA, most first, the state-mean marker
 * (mean facilities per LGA listed) and the split by level. stateMean is full precision for the
 * marker's position; stateMeanDisplay is what a person reads (1 dp, en-NG).
 */
final readonly class Coverage
{
    /**
     * @param  list<LgaCount>  $perLga
     * @param  list<LevelCoverage>  $byLevel
     */
    public function __construct(
        public array $perLga,
        public float $stateMean,
        public string $stateMeanDisplay,
        public array $byLevel,
    ) {}
}
