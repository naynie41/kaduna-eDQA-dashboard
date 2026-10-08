<?php

declare(strict_types=1);

namespace App\Domain\Validation\DTOs;

/** An LGA as the validation context holds it. */
final readonly class LgaSnapshot
{
    public function __construct(
        public int $id,
        public string $code,
        public string $name,
    ) {}
}
