<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Queries;

use App\Domain\Ingestion\Enums\PullTrigger;
use App\Domain\Ingestion\Models\PullRun;
use App\Domain\Ingestion\Models\SourceSync;
use App\Domain\Ingestion\Odk\OdkConfig;
use App\Domain\Ingestion\SourceRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

/**
 * The Data sources page's backfill panel (ARCHITECTURE.md §5.6, §9.3), read-only: a backfill is
 * started from the console (D-53). The last backfill run of any source, ODK Central's checkpoint
 * as a position and a 0-based page (what --from-page takes), and the newest backfill report on
 * the exports disk.
 */
final class BackfillStatusQuery
{
    /** A backfill report's file name on the exports disk (BackfillReportWriter). */
    public const REPORT_PATTERN = '/^backfill-report-\d{8}-\d{6}\.(md|csv)$/';

    public function __construct(
        private readonly SourceRegistry $sources,
    ) {}

    /**
     * @return array{run: ?array{id: int, outcome: ?string, started_at: string, finished_at: ?string, fetched: int, accepted: int, flagged: int, quarantined: int, duplicates: int}, checkpoint: array{position: int, page: int, page_size: int}, report: ?array{file: string, at: string}}
     */
    public function backfill(): array
    {
        $config = OdkConfig::fromConfig();
        $sync = $config->isConfigured()
            ? SourceSync::current($this->sources->id('odk'), $config->formRef())
            : null;
        $run = PullRun::query()->where('trigger_type', PullTrigger::Backfill)->latest('started_at')->latest('id')->first();
        $position = $sync === null ? 0 : (int) $sync->backfill_checkpoint;
        $pageSize = max(1, (int) config('edqa.sources.odk.page_size', 500));

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
}
