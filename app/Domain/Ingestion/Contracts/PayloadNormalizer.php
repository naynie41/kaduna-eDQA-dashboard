<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Contracts;

use App\Domain\Ingestion\DTOs\SubmissionEnvelope;

/**
 * A source's raw submission JSON → the common SubmissionEnvelope (ARCHITECTURE.md §5.2). Pure and
 * deterministic: no I/O, no clock, no config of the source's connection; the same payload always
 * gives the same envelope, so reprocessing reads the stored payload through it again (hard rule
 * 8). It never throws on bad data: what it cannot read is left null or empty for validation.
 */
interface PayloadNormalizer
{
    /** @param  array<array-key, mixed>  $payload  as the source sent it, verbatim */
    public function normalise(array $payload): SubmissionEnvelope;
}
