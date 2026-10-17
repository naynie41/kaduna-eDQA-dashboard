<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Ingestion\Actions\SendDemoRowToQuarantine;
use App\Domain\Validation\DTOs\RuleFailure;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Holds the old report's broken row in quarantine through the real pipeline, for the client demo
 * (docs/demo-script.md). Local and testing only. See SendDemoRowToQuarantine.
 */
final class DemoQuarantine extends Command
{
    protected $signature = 'edqa:demo:quarantine';

    protected $description = 'Hold the old report\'s broken row in quarantine through the real pipeline (local demo only)';

    public function handle(SendDemoRowToQuarantine $send): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error((string) __('demo.local_only'));

            return self::FAILURE;
        }

        try {
            $outcome = $send->execute();
        } catch (RuntimeException) {
            $this->error((string) __('demo.no_round'));

            return self::FAILURE;
        }

        if ($outcome === null) {
            $this->info((string) __('demo.already_held'));

            return self::SUCCESS;
        }

        $this->info((string) __('demo.held', [
            'status' => $outcome->status->value,
            'record' => (string) $outcome->quarantinedRecordId,
            'codes' => implode(', ', array_map(fn (RuleFailure $failure): string => $failure->code, $outcome->result->hardFailures)),
        ]));

        return self::SUCCESS;
    }
}
