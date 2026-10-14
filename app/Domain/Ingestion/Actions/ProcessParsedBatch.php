<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Actions;

use App\Domain\Ingestion\DTOs\ParsedSubmission;
use App\Domain\Ingestion\DTOs\ProcessOutcome;
use App\Domain\Ingestion\Models\Submission;
use App\Domain\Validation\ValidationContextFactory;

/**
 * Processes one batch (one ODK page): a single ValidationContext for all of it, then each
 * submission in order, each in its own transaction. Order matters: a duplicate or an assessor's
 * extra visit later in the batch is caught against the ones accepted before it.
 */
final class ProcessParsedBatch
{
    public function __construct(
        private readonly ValidationContextFactory $contexts,
        private readonly ProcessParsedSubmission $process,
    ) {}

    /**
     * @param  list<array{0: Submission, 1: ParsedSubmission}>  $batch
     * @return list<ProcessOutcome>
     */
    public function execute(array $batch): array
    {
        $context = $this->contexts->forBatch(array_map(fn (array $pair): ParsedSubmission => $pair[1], $batch));

        $outcomes = [];
        foreach ($batch as [$submission, $parsed]) {
            $outcomes[] = $this->process->execute($submission, $parsed, $context);
        }

        return $outcomes;
    }
}
