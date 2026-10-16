<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\DTOs;

/** An accepted assessment's ODK origin (AssessmentSourceQuery). */
final readonly class AssessmentSource
{
    /**
     * @param  list<array{id: int, filename: string, mime: string, size: int, status: string}>  $attachments
     */
    public function __construct(
        public string $instanceId,
        public ?string $centralUrl,
        public array $attachments,
    ) {}
}
