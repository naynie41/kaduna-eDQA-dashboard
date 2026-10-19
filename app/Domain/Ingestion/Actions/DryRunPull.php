<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Actions;

use App\Domain\Ingestion\Contracts\SubmissionSource;
use App\Domain\Ingestion\DTOs\DryRunReport;
use App\Domain\Ingestion\DTOs\ParsedSubmission;
use App\Domain\Ingestion\Exceptions\OdkException;
use App\Domain\Ingestion\Models\OdkFormSync;
use App\Domain\Ingestion\Odk\OdkConfig;
use App\Domain\Ingestion\Odk\SubmissionParser;
use App\Domain\Validation\ValidationContextFactory;
use App\Domain\Validation\ValidationPipeline;
use Illuminate\Support\Facades\DB;

/**
 * `edqa:pull --dry-run` (ARCHITECTURE.md §5.3): the first pull against real credentials, made safe.
 * Fetches up to $limit new submissions from the stored cursor, parses and validates them in
 * memory against the live reference data, and reports what a real pull would do, by rule code.
 *
 * Writes nothing: no submission, no cursor, no run row, no audit entry (reads only). The ODK
 * session token is cached as for any request.
 */
final class DryRunPull
{
    public function __construct(
        private readonly SubmissionSource $source,
        private readonly SubmissionParser $parser,
        private readonly ValidationContextFactory $contexts,
        private readonly ValidationPipeline $pipeline,
    ) {}

    /** @throws OdkException */
    public function execute(int $limit): DryRunReport
    {
        $limit = max(1, $limit);
        $config = OdkConfig::fromConfig();
        $sync = $config->isConfigured()
            ? OdkFormSync::query()->where('project_id', (int) $config->projectId)->where('form_id', (string) $config->formId)->first()
            : null;
        $cursor = $sync?->last_pulled_at;

        $rows = [];
        $skip = 0;
        while (count($rows) < $limit) {
            $page = $this->source->submissionsPage($cursor, min($limit - count($rows), $config->pageSize), $skip, inclusive: $cursor !== null);
            $rows = [...$rows, ...$page->rows];
            $skip += count($page->rows);
            if (! $page->hasMore || $page->rows === []) {
                break;
            }
        }

        /** @var list<ParsedSubmission> $parsed */
        $parsed = array_map(fn (array $row): ParsedSubmission => $this->parser->parse($row), $rows);
        $stored = $parsed === [] ? [] : DB::table('submissions')
            ->whereIn('instance_id', array_map(fn (ParsedSubmission $p): string => $p->instanceId, $parsed))
            ->pluck('instance_id')->flip()->all();

        // As ProcessParsedBatch does it: one context, accepted ones registered as they pass.
        $fresh = array_values(array_filter($parsed, fn (ParsedSubmission $p): bool => ! isset($stored[$p->instanceId])));
        $context = $this->contexts->forBatch($fresh);
        $outcomes = ['accepted' => 0, 'flagged' => 0, 'quarantined' => 0];
        $rules = [];
        foreach ($fresh as $submission) {
            $result = $this->pipeline->validate($submission, $context);
            $outcome = ! $result->passed() ? 'quarantined' : ($result->isFlagged() ? 'flagged' : 'accepted');
            $outcomes[$outcome]++;
            if ($result->passed()) {
                $context->registerAccepted($submission);
            }
            foreach ($result->failures() as $failure) {
                $rules[$failure->code] ??= ['severity' => $failure->severity->value, 'count' => 0];
                $rules[$failure->code]['count']++;
            }
        }
        ksort($rules);

        return new DryRunReport(count($rows), count($parsed) - count($fresh), $outcomes, $rules);
    }
}
