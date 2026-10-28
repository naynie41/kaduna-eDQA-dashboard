<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Ingestion\Enums\PullTrigger;
use App\Domain\Ingestion\Models\PullRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A finished scheduled pull. outcome is left unset: its values are an open question.
 *
 * @extends Factory<PullRun>
 */
final class PullRunFactory extends Factory
{
    protected $model = PullRun::class;

    public function definition(): array
    {
        return [
            'source_id' => fn (): int => SubmissionSourceFactory::idFor('odk'),
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
