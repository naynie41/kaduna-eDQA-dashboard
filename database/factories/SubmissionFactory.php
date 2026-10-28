<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Ingestion\Enums\SubmissionStatus;
use App\Domain\Ingestion\Models\Submission;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A raw record from the odk source in its received state (fromSource('kobo') for another). Realistic broken payloads for validation live in
 * tests/Fixtures/odk (CONVENTION.md §8), not here.
 *
 * @extends Factory<Submission>
 */
final class SubmissionFactory extends Factory
{
    protected $model = Submission::class;

    public function definition(): array
    {
        $instanceId = 'uuid:'.fake()->uuid();

        return [
            'source_id' => fn (): int => SubmissionSourceFactory::idFor('odk'),
            'external_id' => $instanceId,
            'instance_id' => $instanceId,
            'form_ref' => 'dqa',
            'form_version' => '2026.1',
            'payload' => [
                '__id' => $instanceId,
                'meta' => ['instanceID' => $instanceId],
                'facility' => ['lga' => 'chikun', 'ward' => 'kawo', 'facility_code' => 'KD/TST/1'],
            ],
            'submitted_at' => now()->subHour(),
            'received_at' => now(),
            'status' => SubmissionStatus::Received,
        ];
    }

    /** A submission from another configured source (its row is created once). */
    public function fromSource(string $key): self
    {
        return $this->state(['source_id' => fn (): int => SubmissionSourceFactory::idFor($key)]);
    }

    public function accepted(): self
    {
        return $this->state(['status' => SubmissionStatus::Accepted, 'processed_at' => now()]);
    }

    public function quarantined(): self
    {
        return $this->state(['status' => SubmissionStatus::Quarantined, 'processed_at' => now()]);
    }
}
