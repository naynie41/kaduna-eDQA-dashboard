<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\DTOs;

/** An accepted assessment's origin (AssessmentSourceQuery): its source, instance ID and a link to it there. */
final readonly class AssessmentSource
{
    /**
     * @param  list<array{id: int, filename: string, mime: ?string, size: ?int, status: string, reason: ?string, url: ?string}>  $attachments
     */
    public function __construct(
        public ?string $sourceKey,
        public ?string $sourceName,
        public string $instanceId,
        public ?string $url,
        public array $attachments,
    ) {}
}
