<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Exceptions;

use App\Domain\Ingestion\Models\Submission;
use LogicException;

/**
 * Raw ODK payloads are never mutated (CLAUDE.md hard rule 8). Corrections go in an overlay
 * (D-08); reprocessing reads the stored payload.
 */
final class SubmissionPayloadIsImmutable extends LogicException
{
    public static function for(Submission $submission): self
    {
        return new self("The ODK payload of submission {$submission->instance_id} cannot be changed after it is stored.");
    }
}
