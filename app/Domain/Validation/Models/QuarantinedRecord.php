<?php

declare(strict_types=1);

namespace App\Domain\Validation\Models;

use App\Domain\Ingestion\Models\Submission;
use App\Domain\Round\Models\Round;
use App\Domain\Validation\Enums\QuarantineResolution;
use App\Domain\Validation\Enums\QuarantineStatus;
use App\Models\User;
use App\Support\Audit\Audited;
use Carbon\CarbonImmutable;
use Database\Factories\QuarantinedRecordFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A submission that failed at least one hard rule, with every failure, hard and soft
 * ([{code, severity, detail, field}]), the parsed snapshot and any correction overlay.
 * Reporting never reads this (CLAUDE.md hard rule 6). The audit trail records corrections and
 * resolution, not the payload.
 *
 * @property int $source_id
 * @property int|null $round_id
 * @property array<string, mixed> $payload
 * @property list<array<string, mixed>> $failures
 * @property array<string, mixed>|null $parsed
 * @property array<string, mixed> $corrections
 * @property QuarantineStatus $status
 * @property QuarantineResolution|null $resolution
 * @property CarbonImmutable|null $resolved_at
 * @property CarbonImmutable|null $created_at
 */
#[UseFactory(QuarantinedRecordFactory::class)]
final class QuarantinedRecord extends Model
{
    use Audited;

    /** @use HasFactory<QuarantinedRecordFactory> */
    use HasFactory;

    protected $fillable = [
        'source_id', 'submission_id', 'instance_id', 'round_id', 'facility_ref', 'lga_ref', 'payload', 'failures',
        'parsed', 'corrections', 'status', 'resolution', 'resolved_by', 'resolved_at', 'resolution_note',
    ];

    /** Mirrors the column defaults, so a new model matches its stored row. */
    protected $attributes = ['status' => 'open', 'corrections' => '{}'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'failures' => 'array',
            'parsed' => 'array',
            'corrections' => 'array',
            'status' => QuarantineStatus::class,
            'resolution' => QuarantineResolution::class,
            'resolved_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Submission, $this> */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }

    /** @return BelongsTo<Round, $this> */
    public function round(): BelongsTo
    {
        return $this->belongsTo(Round::class);
    }

    /** @return BelongsTo<User, $this> */
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    protected function auditedAttributes(): array
    {
        return ['corrections', 'status', 'resolution', 'resolved_by', 'resolved_at', 'resolution_note'];
    }
}
