<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Ingestion\Models\Submission;
use App\Domain\Validation\Enums\QuarantineStatus;
use App\Domain\Validation\Enums\Severity;
use App\Domain\Validation\Models\QuarantinedRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An open quarantined record for a quarantined submission, carrying the submission's instance
 * ID and a copy of its payload.
 *
 * @extends Factory<QuarantinedRecord>
 */
final class QuarantinedRecordFactory extends Factory
{
    protected $model = QuarantinedRecord::class;

    /** Soft rules flag rather than quarantine (ARCHITECTURE.md §6). */
    private const SOFT_RULES = ['SCORE_JUMP', 'ALL_PERFECT', 'VISIT_TOO_SHORT', 'ASSESSOR_VOLUME'];

    public function definition(): array
    {
        return [
            'submission_id' => Submission::factory()->quarantined(),
            'instance_id' => fn (array $a): string => (string) Submission::query()->whereKey($a['submission_id'])->value('instance_id'),
            'payload' => fn (array $a): array => Submission::query()->findOrFail($a['submission_id'], ['payload'])->payload,
            'failures' => self::failures(['LGA_UNKNOWN']),
            'status' => QuarantineStatus::Open,
        ];
    }

    public function failing(string ...$codes): self
    {
        return $this->state(['failures' => self::failures($codes)]);
    }

    /**
     * @param  array<string>  $codes
     * @return list<array{code: string, severity: string, detail: string}>
     */
    private static function failures(array $codes): array
    {
        return array_values(array_map(fn (string $code): array => [
            'code' => $code,
            'severity' => in_array($code, self::SOFT_RULES, true) ? Severity::Soft->value : Severity::Hard->value,
            'detail' => "Factory failure for {$code}",
        ], $codes));
    }
}
