<?php

declare(strict_types=1);

namespace App\Domain\Validation\DTOs;

/** A master-list facility as the validation context holds it, active or not. */
final readonly class FacilitySnapshot
{
    public function __construct(
        public int $id,
        public string $code,
        public int $lgaId,
        public bool $isActive,
    ) {}
}
