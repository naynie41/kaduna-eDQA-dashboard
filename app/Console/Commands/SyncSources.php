<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Ingestion\Actions\SyncSubmissionSources;
use Illuminate\Console\Command;

/** Brings submission_sources in step with config/edqa.php (sources). Never deletes a row. */
final class SyncSources extends Command
{
    protected $signature = 'edqa:sources:sync';

    protected $description = 'Create or update the submission_sources rows from config (never deletes)';

    public function handle(SyncSubmissionSources $sync): int
    {
        foreach ($sync->execute() as $row) {
            $this->line((string) __("sources.sync.{$row['outcome']}", [
                'key' => $row['key'],
                'name' => (string) config("edqa.sources.{$row['key']}.name", $row['key']),
                'changes' => implode(', ', $row['changes']),
            ]));
        }

        return self::SUCCESS;
    }
}
