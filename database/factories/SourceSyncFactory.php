<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Ingestion\Models\SourceSync;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SourceSync> */
final class SourceSyncFactory extends Factory
{
    protected $model = SourceSync::class;

    public function definition(): array
    {
        return [
            'source_id' => fn (): int => SubmissionSourceFactory::idFor('odk'),
            'form_ref' => '1/dqa',
            'backfill_checkpoint' => 0,
            'submission_count' => 0,
        ];
    }

    public function fromSource(string $key, ?string $formRef = null): self
    {
        return $this->state(['source_id' => fn (): int => SubmissionSourceFactory::idFor($key), ...($formRef === null ? [] : ['form_ref' => $formRef])]);
    }
}
