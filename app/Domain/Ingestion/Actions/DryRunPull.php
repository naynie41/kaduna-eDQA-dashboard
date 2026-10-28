<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Actions;

use App\Domain\Ingestion\Contracts\SubmissionSource;
use App\Domain\Ingestion\DTOs\DryRunReport;
use App\Domain\Ingestion\DTOs\ParsedSubmission;
use App\Domain\Ingestion\Exceptions\OdkException;
use App\Domain\Ingestion\Models\SourceSync;
use App\Domain\Ingestion\Odk\OdkConfig;
use App\Domain\Ingestion\Odk\OdkPayloadNormalizer;
use App\Domain\Ingestion\SourceRegistry;
use App\Domain\Ingestion\SubmissionParser;
use App\Domain\Validation\ValidationContextFactory;
use App\Domain\Validation\ValidationPipeline;
use Illuminate\Support\Facades\DB;

/**
 * `edqa:pull --dry-run` and `edqa:backfill --dry-run` (ARCHITECTURE.md §5.3, §5.6): the first
 * contact with real credentials, made safe. Fetches up to $limit submissions, from the stored
 * cursor (a pull) or from the start of the form (a backfill, $fromStart), parses and validates
 * them in memory against the live reference data, and reports what a real run would do, by rule.
 *
 * Page by page, as the real run: one ValidationContext a page, only the counts kept, so a dry run
 * of the whole history fits in memory. Unlike the real run, nothing is stored between pages, so a
 * second visit to a facility on a later page than the first is not caught as a duplicate here.
 *
 * Writes nothing: no submission, no cursor, no run row, no audit entry (reads only). The ODK
 * session token is cached as for any request.
 */
final class DryRunPull
{
    public function __construct(
        private readonly SubmissionSource $source,
        private readonly SubmissionParser $parser,
        private readonly OdkPayloadNormalizer $normaliser,
        private readonly ValidationContextFactory $contexts,
        private readonly ValidationPipeline $pipeline,
        private readonly SourceRegistry $sources,
    ) {}

    /** @throws OdkException */
    public function execute(int $limit, bool $fromStart = false): DryRunReport
    {
        $limit = max(1, $limit);
        $config = OdkConfig::fromConfig();
        $sync = $config->isConfigured() && ! $fromStart
            ? SourceSync::current($this->sources->id('odk'), $config->formRef())
            : null;
        $cursor = $sync?->cursor_at;

        $fetched = 0;
        $stored = 0;
        $outcomes = ['accepted' => 0, 'flagged' => 0, 'quarantined' => 0];
        $rules = [];
        $skip = 0;
        while ($fetched < $limit) {
            $page = $this->source->submissionsPage($cursor, min($limit - $fetched, $config->pageSize), $skip, inclusive: $cursor !== null);
            $fetched += count($page->rows);
            $skip += count($page->rows);

            /** @var list<ParsedSubmission> $parsed */
            $parsed = array_map(fn (array $row): ParsedSubmission => $this->parser->parse($this->normaliser->normalise($row)), $page->rows);
            $known = $parsed === [] ? [] : DB::table('submissions')
                ->whereIn('instance_id', array_map(fn (ParsedSubmission $p): string => $p->instanceId, $parsed))
                ->pluck('instance_id')->flip()->all();
            $fresh = array_values(array_filter($parsed, fn (ParsedSubmission $p): bool => ! isset($known[$p->instanceId])));
            $stored += count($parsed) - count($fresh);

            // As ProcessParsedBatch does it: one context, accepted ones registered as they pass.
            $context = $this->contexts->forBatch($fresh);
            foreach ($fresh as $submission) {
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
