<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Round\Models\Round;
use App\Support\OdkMock\MockSubmissionGenerator;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Fills the mock ODK Central (docker/odk-mock, `make odk-mock-seed n=200`) with raw submissions for
 * the seeded facilities. Local development and tests only. --apply-edits makes the staged edited
 * versions visible, as if assessors had just edited them in Central.
 */
final class GenerateOdkMock extends Command
{
    protected $signature = 'edqa:odk-mock:generate
        {--count=200 : Submissions to write}
        {--broken=10 : How many of them must be quarantined}
        {--round= : The round, as 2026Q2 (default: the open round, else the latest)}
        {--form-version=2026.1 : The form version they are submitted with (--version is reserved by the console)}
        {--seed=2026 : Same options and seed, same data}
        {--apply-edits : Only make the staged edits visible to the next pull}';

    protected $description = 'Write raw submissions for the local mock ODK Central (local only)';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error((string) __('odk.mock.local_only'));

            return self::FAILURE;
        }
        $generator = new MockSubmissionGenerator((string) config('edqa.odk.mock_path'));

        try {
            if ($this->option('apply-edits')) {
                $generator->applyEdits();
                $this->info((string) __('odk.mock.edits_applied'));

                return self::SUCCESS;
            }
            $written = $generator->generate(
                max(1, (int) $this->option('count')),
                max(0, (int) $this->option('broken')),
                $this->round(),
                (string) $this->option('form-version'),
                (int) $this->option('seed'),
            );
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info((string) __('odk.mock.written', [...array_map(strval(...), $written), 'path' => (string) config('edqa.odk.mock_path')]));

        return self::SUCCESS;
    }

    private function round(): ?Round
    {
        $asked = $this->option('round');
        if (! is_string($asked) || $asked === '') {
            return null;
        }
        if (preg_match('/^(\d{4})Q([1-4])$/i', $asked, $m) !== 1) {
            throw new RuntimeException((string) __('odk.mock.bad_round', ['round' => $asked]));
        }

        return Round::query()->where('year', (int) $m[1])->where('quarter', (int) $m[2])->first()
            ?? throw new RuntimeException((string) __('odk.mock.no_round', ['round' => $asked]));
    }
}
