<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Database\Seeders\PerformanceSeeder;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Loads the performance dataset (ARCHITECTURE.md §10): every quarter of five years, one scored
 * assessment per active facility (~44,000), then refreshes round_aggregates. Local and testing
 * only; never production. See PerformanceSeeder.
 */
final class SeedPerformanceData extends Command
{
    protected $signature = 'edqa:seed:perf
        {--years=5 : Years of quarterly rounds, ending at the latest round}
        {--items-per-slot=10 : Item responses per dimension × month}';

    protected $description = 'Load the performance dataset: five years of scored assessments (local only)';

    public function handle(PerformanceSeeder $seeder): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error((string) __('perf.local_only'));

            return self::FAILURE;
        }

        $seeder->years = max(1, (int) $this->option('years'));
        $seeder->itemsPerSlot = max(1, (int) $this->option('items-per-slot'));

        $started = hrtime(true);
        try {
            $seeder->run();
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info((string) __('perf.done', [
            'assessments' => number_format($seeder->created),
            'rounds' => count($seeder->rounds),
            'seconds' => number_format((hrtime(true) - $started) / 1e9, 1),
        ]));

        return self::SUCCESS;
    }
}
