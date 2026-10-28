<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Models;

use App\Domain\Assessment\Models\Assessment;
use App\Domain\Ingestion\Enums\SubmissionStatus;
use App\Domain\Ingestion\Exceptions\SubmissionPayloadIsImmutable;
use App\Domain\Validation\Models\QuarantinedRecord;
use Carbon\CarbonImmutable;
use Database\Factories\SubmissionFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One record from a field-collection source (source_id), stored verbatim. external_id is the
 * source's logical ID (ODK Central's __id), shared by every version of an edited submission. The payload can never change after creation (CLAUDE.md
 * hard rule 8); status and timestamps move through the lifecycle. Not audited: raw input.
 *
 * @property int $source_id
 * @property string|null $external_id
 * @property string $form_ref
 * @property CarbonImmutable|null $source_updated_at
 * @property CarbonImmutable|null $deleted_at_source
 * @property array<string, mixed> $payload
 * @property SubmissionStatus $status
 * @property CarbonImmutable $submitted_at
 * @property CarbonImmutable $received_at
 * @property CarbonImmutable|null $processed_at
 */
#[UseFactory(SubmissionFactory::class)]
final class Submission extends Model
{
    /** @use HasFactory<SubmissionFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'source_id', 'external_id', 'instance_id', 'deprecated_id', 'form_ref', 'form_version', 'payload', 'submitted_at',
        'source_updated_at', 'received_at', 'processed_at', 'status', 'superseded_by_id', 'deleted_at_source',
    ];

    protected static function booted(): void
    {
        self::updating(function (Submission $submission): void {
            if ($submission->isDirty('payload')) {
                throw SubmissionPayloadIsImmutable::for($submission);
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'status' => SubmissionStatus::class,
            'submitted_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
            'source_updated_at' => 'immutable_datetime',
            'deleted_at_source' => 'immutable_datetime',
            'source_id' => 'integer',
        ];
    }

    /** @return BelongsTo<SubmissionSource, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(SubmissionSource::class, 'source_id');
    }

    /** @return HasOne<Assessment, $this> */
    public function assessment(): HasOne
    {
        return $this->hasOne(Assessment::class);
    }

    /** @return HasMany<QuarantinedRecord, $this> */
    public function quarantinedRecords(): HasMany
    {
        return $this->hasMany(QuarantinedRecord::class);
    }

    /** @return HasMany<SubmissionAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(SubmissionAttachment::class);
    }

    /**
     * The ODK edit that replaced this submission.
     *
     * @return BelongsTo<self, $this>
     */
    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_id');
    }
}
