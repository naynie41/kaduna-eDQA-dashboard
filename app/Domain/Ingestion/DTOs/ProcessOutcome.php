<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\DTOs;

use App\Domain\Ingestion\Enums\SubmissionStatus;
use App\Domain\Validation\DTOs\ValidationResult;

/**
 * What processing one submission did: accepted (or flagged) into an assessment, or quarantined.
 * supersededSubmissionId is set when an ODK edit replaced an accepted submission in place.
 */
final readonly class ProcessOutcome
{
    public function __construct(
        public SubmissionStatus $status,
        public ValidationResult $result,
        public ?int $assessmentId = null,
        public ?int $quarantinedRecordId = null,
        public ?int $supersededSubmissionId = null,
    ) {}
}
