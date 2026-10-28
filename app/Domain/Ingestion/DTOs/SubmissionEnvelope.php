<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\DTOs;

/**
 * A submission from any source in the common shape (ARCHITECTURE.md §5.2): the source's facts
 * about it (meta) and the answers, nested by group path (data). Each source's
 * PayloadNormalizer builds it from the raw payload; the parser reads only this, so it cannot tell
 * the sources apart.
 */
final readonly class SubmissionEnvelope
{
    /** @param  array<array-key, mixed>  $data  answers, nested by group path */
    public function __construct(
        public SubmissionMeta $meta,
        public array $data,
    ) {}
}
