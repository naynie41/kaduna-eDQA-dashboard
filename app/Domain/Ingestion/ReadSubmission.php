<?php

declare(strict_types=1);

namespace App\Domain\Ingestion;

use App\Domain\Ingestion\DTOs\ParsedSubmission;
use App\Domain\Ingestion\Models\Submission;

/**
 * A raw payload read the one way (ARCHITECTURE.md §5.2): its source's normaliser, then the parser.
 * fromStored() reads a stored submission through its own source's normaliser, never re-fetching
 * (hard rule 8): what reprocessing uses.
 */
final class ReadSubmission
{
    public function __construct(
        private readonly SourceRegistry $sources,
        private readonly SubmissionParser $parser,
    ) {}

    /** @param  array<array-key, mixed>  $payload */
    public function fromPayload(string $source, array $payload): ParsedSubmission
    {
        return $this->parser->parse($this->sources->normaliser($source)->normalise($payload));
    }

    public function fromStored(Submission $submission): ParsedSubmission
    {
        $key = $submission->source()->value('key');

        return $this->fromPayload(is_string($key) ? $key : '', $submission->payload);
    }
}
