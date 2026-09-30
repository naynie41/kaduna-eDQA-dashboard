<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Models;

use App\Domain\Facility\Models\Facility;
use App\Domain\Ingestion\Models\Submission;
use App\Domain\Round\Models\Round;
use App\Support\Audit\Audited;
use Database\Factories\AssessmentFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One visit to one facility in one round, created only from a submission that passed every
 * hard rule. An ODK edit updates it in place (audited).
 *
 * status has no enum yet: its values are an open question.
 */
#[UseFactory(AssessmentFactory::class)]
final class Assessment extends Model
{
    use Audited;

    /** @use HasFactory<AssessmentFactory> */
    use HasFactory;

    protected $fillable = [
        'round_id', 'facility_id', 'submission_id', 'assessor_name', 'device_id', 'started_at',
        'ended_at', 'gps', 'status', 'flags',
    ];

    /** Mirrors the column default, so a new model matches its stored row. */
    protected $attributes = ['flags' => '[]'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
            'gps' => 'array',
            'flags' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Round, $this> */
    public function round(): BelongsTo
    {
        return $this->belongsTo(Round::class);
    }

    /** @return BelongsTo<Facility, $this> */
    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    /** @return BelongsTo<Submission, $this> */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }

    /** @return HasMany<ItemResponse, $this> */
    public function itemResponses(): HasMany
    {
        return $this->hasMany(ItemResponse::class);
    }

    /**
     * Scores for every rule version; filter by rule_version_id for one.
     *
     * @return HasMany<AssessmentScore, $this>
     */
    public function scores(): HasMany
    {
        return $this->hasMany(AssessmentScore::class);
    }

    protected function auditedAttributes(): array
    {
        return [
            'round_id', 'facility_id', 'submission_id', 'assessor_name', 'device_id', 'started_at',
            'ended_at', 'gps', 'status', 'flags',
        ];
    }
}
