<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\DTOs;

/** One attachment a submission names (ARCHITECTURE.md §5.2): its name, type and, when the source gives one, its URL. */
final readonly class EnvelopeAttachment
{
    public function __construct(
        public string $name,
        public ?string $mime = null,
        public ?string $url = null,
    ) {}
}
