<?php

declare(strict_types=1);

namespace App\Domain\Validation\Models;

use App\Domain\Ingestion\Models\Submission;
use App\Domain\Round\Models\Round;
use App\Domain\Validation\Enums\QuarantineResolution;
use App\Domain\Validation\Enums\QuarantineStatus;
use App\Models\User;
use App\Support\Audit\Audited;
use Database\Factories\QuarantinedRecordFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A submission that failed at least one hard rule, with every failure
 * ([{code, severity, detail}]). Reporting never reads this (CLAUDE.md hard rule 6).
 * The audit trail records its resolution, not the payload.
 */
#[UseFactory(QuarantinedRecordFactory::class)]
final class QuarantinedRecord extends Model
{
    use Audited;

    /** @use HasFactory<QuarantinedRecordFactory> */
    use HasFactory;

    protected $fillable = [
        'submission_id', 'instance_id', 'round_id', 'facility_ref', 'lga_ref', 'payload', 'failures',
        'status', 'resolution', 'resolved_by', 'resolved_at', 'resolution_note',
    ];

    /** Mirrors the column default, so a new model matches its stored row. */
    protected $attributes = ['status' => 'open'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'failures' => 'array',
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
        return ['status', 'resolution', 'resolved_by', 'resolved_at', 'resolution_note'];
    }
}
