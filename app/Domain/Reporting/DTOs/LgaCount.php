<?php

declare(strict_types=1);

namespace App\Domain\Reporting\DTOs;

/** Facilities visited in one LGA (Monitor, ranked bars §9.2.4). */
final readonly class LgaCount
{
    public function __construct(
        public int $lgaId,
        public string $code,
        public string $name,
        public int $facilities,
    ) {}
}
