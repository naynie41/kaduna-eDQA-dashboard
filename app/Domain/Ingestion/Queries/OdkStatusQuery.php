<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Queries;

use App\Domain\Ingestion\Actions\StartManualPull;
use App\Domain\Ingestion\Contracts\SubmissionSource;
use App\Domain\Ingestion\Enums\PullOutcome;
use App\Domain\Ingestion\Enums\PullTrigger;
use App\Domain\Ingestion\Exceptions\OdkException;
use App\Domain\Ingestion\Models\OdkFormSync;
use App\Domain\Ingestion\Models\OdkPullRun;
use App\Domain\Ingestion\Odk\OdkConfig;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * The ingestion half of GET /api/odk/status (ARCHITECTURE.md §8): whether ODK Central is
 * configured (and which env keys are missing, never their values), whether it answers, where the
 * cursors stand, and the pull in progress. Reachability is a real sign-in and project read
 * (SubmissionSource::ping), cached for REACHABLE_SECONDS so a page polling the status does not
 * call Central each time.
 */
final class OdkStatusQuery
{
    public const REACHABLE_SECONDS = 60;

    public const CACHE_KEY = 'edqa.odk.reachable';

    public function __construct(
        private readonly SubmissionSource $source,
    ) {}

    /**
     * @return array{configured: bool, problems: list<string>, reachable: ?array{ok: bool, status: string, message: ?string, checked_at: string}, cursor: ?array{last_pulled_at: ?string, last_edit_at: ?string, last_instance_id: ?string, backfill_skip: int, submission_count: int}, running_run_id: ?int, last_run: ?array{id: int, outcome: ?string, finished_at: ?string, fetched: int, parsed: int, accepted: int, flagged: int, quarantined: int, duplicates: int}}
     */
    public function status(): array
    {
        $config = OdkConfig::fromConfig();
        $configured = $config->isConfigured();
        $sync = $configured
            ? OdkFormSync::query()->where('project_id', (int) $config->projectId)->where('form_id', (string) $config->formId)->first()
            : null;

        return [
            'configured' => $configured,
            'problems' => $config->problems(),
            'reachable' => $configured ? $this->reachable() : null,
            'cursor' => $sync === null ? null : [
                'last_pulled_at' => $sync->last_pulled_at?->toIso8601String(),
                'last_edit_at' => $sync->last_edit_at?->toIso8601String(),
                'last_instance_id' => is_string($sync->last_instance_id) ? $sync->last_instance_id : null,
                'backfill_skip' => (int) $sync->backfill_skip,
                'submission_count' => (int) $sync->submission_count,
            ],
            'running_run_id' => StartManualPull::running()?->id,
            'last_run' => self::lastRun(),
        ];
    }

    /**
     * The last finished incremental pull (not a backfill): "submissions in the last pull".
     *
     * @return array{id: int, outcome: ?string, finished_at: ?string, fetched: int, parsed: int, accepted: int, flagged: int, quarantined: int, duplicates: int}|null
     */
    private static function lastRun(): ?array
    {
        $run = OdkPullRun::query()->whereNotNull('finished_at')->where('trigger_type', '!=', PullTrigger::Backfill)
            ->whereIn('outcome', [PullOutcome::Succeeded, PullOutcome::Failed])->latest('finished_at')->latest('id')->first();

        return $run === null ? null : [
            'id' => $run->id, 'outcome' => $run->outcome?->value, 'finished_at' => $run->finished_at?->toIso8601String(),
            'fetched' => $run->fetched, 'parsed' => $run->parsed, 'accepted' => $run->accepted, 'flagged' => $run->flagged,
            'quarantined' => $run->quarantined, 'duplicates' => $run->duplicates,
        ];
    }

    /**
     * The ODK pull page's backfill panel (read-only: a backfill is started from the console,
     * D-53): the last backfill run, the checkpoint as a position and a 0-based page (what
     * --from-page takes), and the newest backfill report on the exports disk.
     *
     * @return array{run: ?array{id: int, outcome: ?string, started_at: string, finished_at: ?string, fetched: int, accepted: int, flagged: int, quarantined: int, duplicates: int}, checkpoint: array{position: int, page: int, page_size: int}, report: ?array{file: string, at: string}}
     */
    public function backfill(): array
    {
        $config = OdkConfig::fromConfig();
        $sync = $config->isConfigured()
            ? OdkFormSync::query()->where('project_id', (int) $config->projectId)->where('form_id', (string) $config->formId)->first()
            : null;
        $run = OdkPullRun::query()->where('trigger_type', PullTrigger::Backfill)->latest('started_at')->latest('id')->first();
        $position = $sync === null ? 0 : (int) $sync->backfill_skip;
        $pageSize = max(1, (int) config('edqa.odk.page_size', 500));

        $reports = array_values(array_filter(Storage::disk('exports')->files(), fn (string $file): bool => preg_match(self::REPORT_PATTERN, $file) === 1));
        rsort($reports); // the name carries the time: newest first
        $latest = $reports[0] ?? null;

        return [
            'run' => $run === null ? null : [
                'id' => $run->id, 'outcome' => $run->outcome?->value, 'started_at' => $run->started_at->toIso8601String(),
                'finished_at' => $run->finished_at?->toIso8601String(), 'fetched' => $run->fetched, 'accepted' => $run->accepted,
                'flagged' => $run->flagged, 'quarantined' => $run->quarantined, 'duplicates' => $run->duplicates,
            ],
            'checkpoint' => ['position' => $position, 'page' => intdiv($position, $pageSize), 'page_size' => $pageSize],
            'report' => $latest === null ? null : [
                'file' => $latest,
                'at' => CarbonImmutable::createFromTimestamp(Storage::disk('exports')->lastModified($latest))->toIso8601String(),
            ],
        ];
    }

    /** A backfill report's file name on the exports disk (BackfillReportWriter). */
    public const REPORT_PATTERN = '/^backfill-report-\d{8}-\d{6}\.(md|csv)$/';

    /** @return array{ok: bool, status: string, message: ?string, checked_at: string} */
    private function reachable(): array
    {
        /** @var array{ok: bool, status: string, message: ?string, checked_at: string} */
        return Cache::remember(self::CACHE_KEY, self::REACHABLE_SECONDS, function (): array {
            try {
                $this->source->ping();

                return ['ok' => true, 'status' => 'ok', 'message' => null, 'checked_at' => now()->toIso8601String()];
            } catch (OdkException $e) {
                return ['ok' => false, 'status' => $e->status(), 'message' => $e->getMessage(), 'checked_at' => now()->toIso8601String()];
            }
        });
    }
}
