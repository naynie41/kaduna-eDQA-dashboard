<?php

declare(strict_types=1);

namespace App\Domain\Validation\Actions;

use App\Domain\Ingestion\Actions\ProcessParsedSubmission;
use App\Domain\Ingestion\DTOs\ParsedSubmission;
use App\Domain\Ingestion\DTOs\ProcessOutcome;
use App\Domain\Ingestion\Enums\SubmissionStatus;
use App\Domain\Ingestion\Odk\SubmissionParser;
use App\Domain\Validation\Enums\QuarantineResolution;
use App\Domain\Validation\Enums\QuarantineStatus;
use App\Domain\Validation\Events\QuarantineResolved;
use App\Domain\Validation\Exceptions\QuarantineActionRefused;
use App\Domain\Validation\Models\QuarantinedRecord;
use App\Domain\Validation\Support\Reason;
use App\Domain\Validation\ValidationContextFactory;
use App\Models\User;
use App\Support\Audit\AuditLog;
use App\Support\Text\Normalise;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * "Correct and reprocess" (ARCHITECTURE.md §6): an administrator's corrections to mapped fields
 * are merged into quarantined_records.corrections, the submission is rebuilt from its raw ODK
 * payload (submissions.payload, through the parser, as the pull reads it) plus every correction so
 * far, and it runs through the full pipeline again. The parsed snapshot on the record is kept for
 * audit and debugging only; for the same payload the two agree (CorrectAndReprocessTest).
 *
 * The raw payload and the parsed snapshot are never touched (CLAUDE.md hard rule 8). Only the
 * fields in CORRECTABLE can change: never items or scores (hard rule 2). Accepted or flagged →
 * the record is resolved as reprocessed; still failing → it stays open with its new failures.
 */
final class CorrectAndReprocess
{
    /** Field (as stored in the parsed snapshot) => the type it must hold. */
    public const CORRECTABLE = [
        'lga_ref' => 'nullable_string',
        'ward_ref' => 'nullable_string',
        'facility_ref' => 'nullable_string',
        'raw_start' => 'string',
        'raw_end' => 'string',
        'round_year' => 'nullable_year',
        'round_quarter' => 'nullable_quarter',
    ];

    public function __construct(
        private readonly ValidationContextFactory $contexts,
        private readonly ProcessParsedSubmission $process,
        private readonly SubmissionParser $parser,
    ) {}

    /** @param  array<string, mixed>  $corrections */
    public function execute(QuarantinedRecord $record, array $corrections, User $actor, string $reason): ProcessOutcome
    {
        $reason = Reason::require($reason);
        self::validate($corrections);

        return DB::transaction(function () use ($record, $corrections, $actor, $reason): ProcessOutcome {
            $record = QuarantinedRecord::query()->with('submission')->lockForUpdate()->findOrFail($record->id);
            if ($record->status !== QuarantineStatus::Open) {
                throw QuarantineActionRefused::alreadyResolved();
            }
            $before = $record->corrections;
            $after = array_replace($before, $corrections);
            $parsed = self::rebuild($this->parser->parse($record->submission->payload)->toArray(), $after);

            $context = $this->contexts->forBatch([$parsed]);
            $outcome = $this->process->execute($record->submission, $parsed, $context, reprocessing: $record);
            $resolved = $outcome->status !== SubmissionStatus::Quarantined;

            $changes = ['corrections' => $after];
            if ($resolved) {
                $changes += [
                    'status' => QuarantineStatus::Resolved,
                    'resolution' => QuarantineResolution::Reprocessed,
                    'resolved_by' => $actor->id,
                    'resolved_at' => now(),
                    'resolution_note' => $reason,
                ];
            }
            // One explicit entry with the reason instead of the model's own change log.
            activity()->withoutLogs(fn () => $record->update($changes));

            AuditLog::record($record, $actor, 'corrected',
                old: ['corrections' => $before, 'status' => QuarantineStatus::Open->value, 'resolution' => null],
                new: [
                    'corrections' => $after,
                    'status' => ($resolved ? QuarantineStatus::Resolved : QuarantineStatus::Open)->value,
                    'resolution' => $resolved ? QuarantineResolution::Reprocessed->value : null,
                ],
                reason: $reason,
                extra: ['outcome' => $outcome->status->value],
            );

            if ($resolved) {
                QuarantineResolved::dispatch($record->id, $record->round_id, QuarantineResolution::Reprocessed);
            }

            return $outcome;
        });
    }

    /**
     * Only whitelisted fields, each of the right type.
     *
     * @param  array<string, mixed>  $corrections
     */
    public static function validate(array $corrections): void
    {
        foreach ($corrections as $field => $value) {
            $type = self::CORRECTABLE[$field] ?? throw QuarantineActionRefused::fieldNotCorrectable((string) $field);

            $valid = match ($type) {
                'string' => is_string($value),
                'nullable_string' => $value === null || is_string($value),
                'nullable_year' => $value === null || (is_int($value) && $value >= 2000 && $value <= 2100),
                'nullable_quarter' => $value === null || (is_int($value) && $value >= 1 && $value <= 4),
            };

            if (! $valid) {
                throw QuarantineActionRefused::invalidCorrection((string) $field);
            }
        }
    }

    /**
     * The snapshot with the corrections laid over it. A corrected start or end is parsed again,
     * as the parser would; if it still is not an ISO date the parsed time stays empty and
     * COLUMN_DRIFT reports it.
     *
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $corrections
     */
    private static function rebuild(array $snapshot, array $corrections): ParsedSubmission
    {
        $data = array_replace($snapshot, $corrections);

        foreach (['raw_start' => 'started_at', 'raw_end' => 'ended_at'] as $raw => $parsedField) {
            if (is_string($corrections[$raw] ?? null)) {
                $data[$parsedField] = Normalise::isIsoDate($corrections[$raw])
                    ? CarbonImmutable::parse($corrections[$raw])->toIso8601String()
                    : null;
            }
        }

        try {
            return ParsedSubmission::fromArray($data);
        } catch (InvalidArgumentException) {
            throw QuarantineActionRefused::invalidCorrection(implode(', ', array_keys($corrections)));
        }
    }
}
