<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Deprecated alias of `edqa:source:check odk` (Phase 2C), kept for one release so runbooks and
 * habits keep working. Same output and JSON, plus a note pointing to the new command.
 */
final class OdkCheck extends Command
{
    protected $signature = 'edqa:odk:check {--json : Print the result as JSON}';

    protected $description = 'Deprecated: use edqa:source:check odk';

    public function handle(): int
    {
        $exit = $this->call('edqa:source:check', ['source' => 'odk', '--json' => (bool) $this->option('json')]);
        if (! $this->option('json')) {
            $this->warn((string) __('sources.check.deprecated_alias'));
        }

        return $exit;
    }
}
