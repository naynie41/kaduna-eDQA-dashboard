<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Models;

use App\Domain\Assessment\Models\Assessment;
use App\Domain\Ingestion\Enums\SubmissionStatus;
use App\Domain\Ingestion\Exceptions\SubmissionPayloadIsImmutable;
use App\Domain\Validation\Models\QuarantinedRecord;
use Database\Factories\SubmissionFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One ODK record, stored verbatim. The payload can never change after creation (CLAUDE.md
 * hard rule 8); status and timestamps move through the lifecycle. Not audited: raw input.
 *
 * @property array<string, mixed> $payload
 */
#[UseFactory(SubmissionFactory::class)]
final class Submission extends Model
{
    /** @use HasFactory<SubmissionFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'instance_id', 'deprecated_id', 'form_id', 'form_version', 'payload', 'submitted_at',
        'received_at', 'processed_at', 'status', 'superseded_by_id',
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
        ];
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

    /** @return HasMany<OdkAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(OdkAttachment::class);
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
