<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Actions;

use App\Domain\Ingestion\DTOs\DryRunReport;
use App\Domain\Ingestion\DTOs\ParsedSubmission;
use App\Domain\Ingestion\Exceptions\SourceException;
use App\Domain\Ingestion\Models\SourceSync;
use App\Domain\Ingestion\SourceRegistry;
use App\Domain\Ingestion\SubmissionParser;
use App\Domain\Validation\ValidationContextFactory;
use App\Domain\Validation\ValidationPipeline;
use Illuminate\Support\Facades\DB;

/**
 * `edqa:pull {source} --dry-run` and `edqa:backfill {source} --dry-run` (ARCHITECTURE.md §5.3,
 * §5.6): the first contact with a source's real credentials, made safe. Fetches up to $limit
 * submissions from that source, from its stored cursor (a pull) or from the start of its form (a
 * backfill, $fromStart), reads them through its normaliser, parses and validates them in memory
 * against the live reference data, and reports what a real run would do, by rule.
 *
 * Page by page, as the real run: one ValidationContext a page, only the counts kept, so a dry run
 * of the whole history fits in memory. Unlike the real run, nothing is stored between pages, so a
 * second visit to a facility on a later page than the first is not caught as a duplicate here.
 *
 * Writes nothing: no submission, no cursor, no run row, no audit entry (reads only). An ODK
 * session token is cached as for any request. A submission another source already holds counts
 * as already stored.
 */
final class DryRunPull
{
    public function __construct(
        private readonly SubmissionParser $parser,
        private readonly ValidationContextFactory $contexts,
        private readonly ValidationPipeline $pipeline,
        private readonly SourceRegistry $sources,
    ) {}

    /** @throws SourceException */
    public function execute(string $source, int $limit, bool $fromStart = false): DryRunReport
    {
        $limit = max(1, $limit);
        if ($this->sources->problems($source) !== []) {
            throw $this->sources->notConfigured($source);
        }
        $adapter = $this->sources->adapter($source);
        $normaliser = $this->sources->normaliser($source);
        $sync = $fromStart ? null : SourceSync::current($this->sources->id($source), $this->sources->formRef($source));
        $cursor = $sync?->cursor_at;
        $pageSize = $this->sources->pageSize($source);

        $fetched = 0;
        $stored = 0;
        $outcomes = ['accepted' => 0, 'flagged' => 0, 'quarantined' => 0, 'out_of_scope' => 0];
        $rules = [];
        $skip = 0;
        while ($fetched < $limit) {
            $page = $adapter->submissionsPage($cursor, min($limit - $fetched, $pageSize), $skip, inclusive: $cursor !== null);
            $fetched += count($page->rows);
            $skip += count($page->rows);

            /** @var list<ParsedSubmission> $parsed */
            $parsed = array_map(fn (array $row): ParsedSubmission => $this->parser->parse($normaliser->normalise($row)), $page->rows);
            $known = $parsed === [] ? [] : DB::table('submissions')
                ->whereIn('instance_id', array_map(fn (ParsedSubmission $p): string => $p->instanceId, $parsed))
                ->pluck('instance_id')->flip()->all();
            $fresh = array_values(array_filter($parsed, fn (ParsedSubmission $p): bool => ! isset($known[$p->instanceId])));
            $stored += count($parsed) - count($fresh);

            // As ProcessParsedBatch does it: one context, accepted ones registered as they pass.
            $context = $this->contexts->forBatch($fresh);
            foreach ($fresh as $submission) {
                if ($submission->outOfScope !== null) {
                    $outcomes['out_of_scope']++; // not a facility assessment (D-74): never validated

                    continue;
                }
                $result = $this->pipeline->validate($submission, $context);
                $outcomes[! $result->passed() ? 'quarantined' : ($result->isFlagged() ? 'flagged' : 'accepted')]++;
                if ($result->passed()) {
                    $context->registerAccepted($submission);
                }
                foreach ($result->failures() as $failure) {
                    $rules[$failure->code] ??= ['severity' => $failure->severity->value, 'count' => 0];
                    $rules[$failure->code]['count']++;
                }
            }

            if (! $page->hasMore || $page->rows === []) {
                break;
            }
        }
        ksort($rules);

        return new DryRunReport($fetched, $stored, $outcomes, $rules);
    }
}
