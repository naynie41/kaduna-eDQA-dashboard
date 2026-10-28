<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Ingestion\Actions\SendDemoDataIssues;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Known broken submissions for the Data issues page, through the real pipeline (D-57): prints the
 * held records as JSON for the Playwright run (`make e2e-issues`). Local and testing only. See
 * SendDemoDataIssues.
 */
final class DemoDataIssues extends Command
{
    protected $signature = 'edqa:demo:data-issues {--batch=a : A tag naming this batch; a new tag sends new records}';

    protected $description = 'Hold known broken submissions in quarantine through the real pipeline (local demo and e2e only)';

    public function handle(SendDemoDataIssues $send): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error((string) __('demo.local_only'));

            return self::FAILURE;
        }

        try {
            $held = $send->execute((string) $this->option('batch'));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line((string) json_encode($held, JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
