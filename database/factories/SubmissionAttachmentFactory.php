<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Ingestion\Models\Submission;
use App\Domain\Ingestion\Models\SubmissionAttachment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An attachment not yet fetched. 'pending' is a placeholder: status values are an open
 * question.
 *
 * @extends Factory<SubmissionAttachment>
 */
final class SubmissionAttachmentFactory extends Factory
{
    protected $model = SubmissionAttachment::class;

    public function definition(): array
    {
        return [
            'submission_id' => Submission::factory(),
            'filename' => 'register-'.fake()->unique()->numberBetween(1, 999_999).'.jpg',
            'mime' => 'image/jpeg',
            'size' => fake()->numberBetween(50_000, 900_000),
            'status' => 'pending',
        ];
    }
}
