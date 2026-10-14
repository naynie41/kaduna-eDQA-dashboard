<?php

declare(strict_types=1);

namespace App\Domain\Scoring\Models;

use App\Domain\Round\Models\Round;
use App\Domain\Scoring\Enums\RescoreStatus;
use App\Domain\Scoring\Enums\RescoreTrigger;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One rescore of a round: who or what asked, under which rule version, and its progress. An
 * operational record like odk_pull_runs; the request is audited on the round
 * (rescore_requested) and every score it changes on the score.
 *
 * @property int $id
 * @property ?string $batch_id
 * @property int $round_id
 * @property int $rule_version_id
 * @property RescoreTrigger $trigger
 * @property ?int $requested_by
 * @property ?string $reason
 * @property RescoreStatus $status
 * @property int $total_assessments
 * @property int $processed_assessments
 * @property int $changed_scores
 * @property int $changed_flags
 * @property int $unscorable
 * @property int $failed_chunks
 * @property CarbonImmutable $started_at
 * @property ?CarbonImmutable $finished_at
 */
final class RescoreRun extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'batch_id', 'round_id', 'rule_version_id', 'trigger', 'requested_by', 'reason', 'status',
        'total_assessments', 'processed_assessments', 'changed_scores', 'changed_flags', 'unscorable',
        'failed_chunks', 'started_at', 'finished_at',
    ];

    /** Mirrors the column defaults, so a new model matches its stored row. */
    protected $attributes = [
        'processed_assessments' => 0, 'changed_scores' => 0, 'changed_flags' => 0, 'unscorable' => 0,
        'failed_chunks' => 0,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'round_id' => 'integer',
            'rule_version_id' => 'integer',
            'trigger' => RescoreTrigger::class,
            'requested_by' => 'integer',
            'status' => RescoreStatus::class,
            'total_assessments' => 'integer',
            'processed_assessments' => 'integer',
            'changed_scores' => 'integer',
            'changed_flags' => 'integer',
            'unscorable' => 'integer',
            'failed_chunks' => 'integer',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Round, $this> */
    public function round(): BelongsTo
    {
        return $this->belongsTo(Round::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
