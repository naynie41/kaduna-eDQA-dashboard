<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\DTOs;

use Carbon\CarbonImmutable;

/** One published version of the ODK form. */
final readonly class FormVersion
{
    public function __construct(
        public string $version,
        public ?CarbonImmutable $publishedAt,
    ) {}
}
