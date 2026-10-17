<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\DTOs;

/** An attachment a submission expects; exists is false until the device has uploaded it. */
final readonly class AttachmentRef
{
    public function __construct(
        public string $name,
        public bool $exists,
    ) {}
}
