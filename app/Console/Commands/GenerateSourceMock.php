<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Round\Models\Round;
use App\Support\SourceMock\SourceMockGenerator;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Fills a local source mock (docker/odk-mock, docker/kobo-mock; `make sources-mock-seed n=200`)
 * with raw submissions for the seeded facilities. Local development and tests only.
 *
 * --edits makes the staged edited versions visible, as if assessors had just edited them at the
 * source (on Kobo only the reconcile sweep finds them). --overlap=N also writes N plain visits of
 * the other mock (generate it first), half copied with the same instance ID (a cross-source
 * duplicate, D-60) and half entered again with a new one (DUPLICATE_ASSESSMENT naming both
 * sources, §5.8). Otherwise the two mocks never visit the same facility.
 */
final class GenerateSourceMock extends Command
{
    protected $signature = 'edqa:source-mock:generate
        {source : odk or kobo}
        {--count=200 : Submissions to write}
        {--broken=10 : How many of them must be quarantined}
        {--edits : Only make the staged edits visible to the next pull or sweep}
        {--overlap=0 : Also write N visits that are on the other mock too}
        {--round= : The round, as 2026Q2 (default: the open round, else the latest)}
        {--form-version=2026.1 : The (ODK) form version they are submitted with; Kobo uses its mapped deployment uid}
        {--seed= : Same options and seed, same data (default 2026 for odk, 2027 for kobo)}';

    protected $description = 'Write raw submissions for a local source mock: ODK Central or KoboToolbox (local only)';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error((string) __('sources.mock.local_only'));

            return self::FAILURE;
        }
        $source = (string) $this->argument('source');
        if (! in_array($source, ['odk', 'kobo'], true)) {
            $this->error((string) __('sources.check.unknown', ['key' => $source, 'keys' => 'odk, kobo']));

            return self::FAILURE;
        }
        $name = (string) config("edqa.sources.{$source}.name", $source);
        $generator = new SourceMockGenerator((string) config('edqa.odk.mock_path'), (string) config('edqa.kobo.mock_path'));

        try {
            if ($this->option('edits')) {
                $generator->applyEdits($source);
                $this->info((string) __('sources.mock.edits_applied', ['source' => $name]));

                return self::SUCCESS;
            }
            $seed = is_numeric($this->option('seed')) ? (int) $this->option('seed') : ($source === 'kobo' ? 2027 : 2026);
            $written = $generator->generate(
                $source,
                max(1, (int) $this->option('count')),
                max(0, (int) $this->option('broken')),
                $this->round(),
                (string) $this->option('form-version'),
                $seed,
                max(0, (int) $this->option('overlap')),
            );
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $path = (string) config($source === 'kobo' ? 'edqa.kobo.mock_path' : 'edqa.odk.mock_path');
        $this->info((string) __('odk.mock.written', [...array_map(strval(...), $written), 'path' => $path]));
        $overlap = $generator->overlap['copies'] + $generator->overlap['reentered'];
        if ($overlap > 0) {
            $this->info((string) __('sources.mock.overlap', [
                'count' => $overlap, 'other' => (string) config('edqa.sources.'.($source === 'kobo' ? 'odk' : 'kobo').'.name'),
                'copies' => $generator->overlap['copies'], 'reentered' => $generator->overlap['reentered'],
            ]));
        }

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
