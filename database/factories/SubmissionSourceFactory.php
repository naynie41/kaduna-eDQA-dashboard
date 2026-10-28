<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Ingestion\Enums\SourceType;
use App\Domain\Ingestion\Models\SubmissionSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A field-collection source row. The key is unique, so factories that need a source call idFor(),
 * which reuses the row (as edqa:sources:sync would have created it).
 *
 * @extends Factory<SubmissionSource>
 */
final class SubmissionSourceFactory extends Factory
{
    protected $model = SubmissionSource::class;

    private const KNOWN = [
        'odk' => [SourceType::OdkCentral, 'ODK Central'],
        'kobo' => [SourceType::Kobo, 'KoboToolbox'],
    ];

    public function definition(): array
    {
        return ['key' => 'odk', 'type' => SourceType::OdkCentral, 'name' => 'ODK Central', 'is_enabled' => true];
    }

    public function key(string $key): self
    {
        [$type, $name] = self::KNOWN[$key] ?? [SourceType::OdkCentral, $key];

        return $this->state(['key' => $key, 'type' => $type, 'name' => $name]);
    }

    public function disabled(): self
    {
        return $this->state(['is_enabled' => false]);
    }

    /** The row for this key, created once. */
    public static function idFor(string $key = 'odk'): int
    {
        $row = SubmissionSource::query()->where('key', $key)->first() ?? SubmissionSource::factory()->key($key)->create();

        return $row->id;
    }
}
