<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Ingestion\Enums\PullTrigger;
use App\Domain\Ingestion\Models\OdkPullRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A finished scheduled pull. outcome is left unset: its values are an open question.
 *
 * @extends Factory<OdkPullRun>
 */
final class OdkPullRunFactory extends Factory
{
    protected $model = OdkPullRun::class;

    public function definition(): array
    {
        return [
            'trigger_type' => PullTrigger::Scheduled,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'fetched' => 12,
            'accepted' => 10,
            'quarantined' => 2,
            'duplicates' => 0,
        ];
    }
}
