<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\DTOs;

/** An attachment written to disk: where, its declared type and its size in bytes. */
final readonly class AttachmentDownload
{
    public function __construct(
        public string $path,
        public ?string $mime,
        public int $size,
    ) {}
}
