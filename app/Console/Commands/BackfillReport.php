<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Ingestion\Queries\BackfillReportQuery;
use App\Domain\Ingestion\Reports\BackfillReportWriter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * The backfill report (ARCHITECTURE.md §5.6): per round, what was pulled, accepted, flagged and
 * quarantined, then the quarantined ones by rule and year, by rule and form version, and the most
 * frequent details of each rule. Written to storage/app/exports as Markdown or CSV.
 */
final class BackfillReport extends Command
{
    protected $signature = 'edqa:backfill-report {--format=md : md or csv}';

    protected $description = 'Write the backfill report to storage/app/exports';

    public function handle(BackfillReportQuery $query, BackfillReportWriter $writer): int
    {
        $format = strtolower((string) $this->option('format'));
        if (! in_array($format, BackfillReportWriter::FORMATS, true)) {
            $this->error((string) __('backfill.report.bad_format', ['formats' => implode(', ', BackfillReportWriter::FORMATS)]));

            return self::FAILURE;
        }

        $path = $writer->write($query->build(), $format);
        $this->info((string) __('backfill.report.written', ['path' => Storage::disk('exports')->path($path)]));

        return self::SUCCESS;
    }
}
