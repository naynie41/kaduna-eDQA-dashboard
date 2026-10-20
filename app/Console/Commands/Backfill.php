<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Ingestion\Actions\BackfillSubmissions;
use App\Domain\Ingestion\Actions\DryRunPull;
use App\Domain\Ingestion\Actions\PullSubmissions;
use App\Domain\Ingestion\Actions\SurveyBackfill;
use App\Domain\Ingestion\DTOs\BackfillProgress;
use App\Domain\Ingestion\Exceptions\BackfillRefused;
use App\Domain\Ingestion\Exceptions\OdkException;
use App\Domain\Ingestion\Odk\BackfillLock;
use Illuminate\Console\Command;
use Throwable;

/**
 * The historical backfill (ARCHITECTURE.md §5.6), in the foreground. The order on day one:
 *
 *   --survey   read every submission, write nothing: counts per quarter and form version, and
 *              which quarters still need a round
 *   (create the historical rounds)
 *   --dry-run  optional: what the backfill would accept, flag and quarantine, writing nothing
 *   (no flag)  the backfill itself, resumable: run it again after a stop and it carries on from
 *              the last committed page; --from-page starts at a page instead
 *
 * then edqa:attachments:fetch-pending and edqa:backfill-report. BackfillOdkSubmissionsJob runs the
 * same backfill on the queue.
 */
final class Backfill extends Command
{
    protected $signature = 'edqa:backfill
        {--survey : Count submissions per quarter and form version; write nothing}
        {--from-page= : Start at this page (0-based) instead of the checkpoint}
        {--throttle= : Seconds between pages (default ODK_BACKFILL_THROTTLE, 5)}
        {--dry-run : Parse and validate every submission in memory; write nothing}';

    protected $description = 'Load every historical ODK submission (resumable), or survey them first';

    public function handle(SurveyBackfill $survey, DryRunPull $dryRun, BackfillSubmissions $backfill, BackfillLock $lock): int
    {
        $throttle = is_numeric($this->option('throttle')) ? max(0, (int) $this->option('throttle')) : null;

        try {
            return match (true) {
                (bool) $this->option('survey') => $this->survey($survey, $throttle),
                (bool) $this->option('dry-run') => $this->dryRun($dryRun),
                default => $this->backfill($backfill, $lock, $throttle),
            };
        } catch (OdkException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function survey(SurveyBackfill $survey, ?int $throttle): int
    {
        $result = $survey->execute($throttle);
        $t = fn (string $key, array $replace = []): string => (string) __("backfill.survey.{$key}", $replace);

        $this->info($t('title', ['total' => $result->total]));
        $this->table(
            [$t('quarter'), $t('version'), $t('mapped'), $t('round'), $t('submissions')],
            array_map(fn (array $row): array => [
                "{$row['year']} Q{$row['quarter']}", $row['version'], $t($row['mapped'] ? 'yes' : 'no'),
                $t('round_'.($row['round'] ?? 'none')), $row['submissions'],
            ], $result->rows),
        );

        $quarters = fn (array $list): string => implode(', ', array_map(fn (array $q): string => "{$q['year']} Q{$q['quarter']} ({$q['submissions']})", $list));
        $missing = $result->quartersWithoutRound();
        $closed = $result->quartersClosed();
        $unmapped = $result->unmappedVersions();
        if ($missing !== []) {
            $this->warn($t('create_rounds', ['quarters' => $quarters($missing)]));
        }
        if ($closed !== []) {
            $this->warn($t('closed_rounds', ['quarters' => $quarters($closed)]));
        }
        if ($unmapped !== []) {
            $this->warn($t('unmapped', ['versions' => implode(', ', $unmapped)]));
        }
        if ($missing === [] && $closed === []) {
            $this->info($t('ready'));
        }

        return self::SUCCESS;
    }

    private function dryRun(DryRunPull $dryRun): int
    {
        $report = $dryRun->execute(PHP_INT_MAX, fromStart: true);

        $this->info((string) __('odk.pull.dry_run_title'));
        $this->line((string) __('odk.pull.dry_run_summary', ['fetched' => $report->fetched, 'stored' => $report->alreadyStored, ...$report->outcomes]));
        if ($report->rules === []) {
            $this->line((string) __('odk.pull.dry_run_no_rules'));
        } else {
            $this->table(
                [(string) __('odk.pull.rule'), (string) __('odk.pull.severity'), (string) __('odk.pull.submissions')],
                array_map(fn (string $code, array $rule): array => [$code, $rule['severity'], $rule['count']], array_keys($report->rules), $report->rules),
            );
        }

        return self::SUCCESS;
    }

    private function backfill(BackfillSubmissions $backfill, BackfillLock $lock, ?int $throttle): int
    {
        $fromPage = is_numeric($this->option('from-page')) ? max(0, (int) $this->option('from-page')) : null;
        $report = function (BackfillProgress $p): void {
            $this->line((string) __('backfill.run.page', [
                'page' => $p->page, 'position' => $p->position, 'total' => $p->total,
                'accepted' => $p->run->accepted, 'flagged' => $p->run->flagged, 'quarantined' => $p->run->quarantined, 'duplicates' => $p->run->duplicates,
            ]));
        };

        try {
            // Held for the whole foreground run; a day is far longer than any backfill should take.
            $done = $lock->run(fn (): BackfillProgress => $backfill->execute($fromPage, $throttle, progress: $report), 86_400);
        } catch (BackfillRefused $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error((string) __('backfill.run.failed', ['error' => PullSubmissions::describe($e)]));

            return self::FAILURE;
        }

        $run = $done->run;
        $this->info((string) __('backfill.run.done', [
            'fetched' => $run->fetched, 'duplicates' => $run->duplicates,
            'accepted' => $run->accepted, 'flagged' => $run->flagged, 'quarantined' => $run->quarantined,
        ]));

        return self::SUCCESS;
    }
}
