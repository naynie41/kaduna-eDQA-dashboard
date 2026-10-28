<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Models;

use App\Domain\Ingestion\Enums\PullOutcome;
use App\Domain\Ingestion\Enums\PullTrigger;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\PullRunFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One pull from one source: powers the pull history and the live progress on the admin page.
 * cross_source_duplicates counts submissions another source already holds (ARCHITECTURE.md
 * §5.3). Manual pulls are audited by the action that starts them (SECURITY.md §4), not here.
 *
 * Columns are listed here because the Phase 2C rename is raw SQL, which Larastan cannot read.
 *
 * @property int $id
 * @property int $source_id
 * @property int|null $triggered_by
 * @property int $fetched
 * @property int $parsed
 * @property int $accepted
 * @property int $flagged
 * @property int $quarantined
 * @property int $duplicates
 * @property int $cross_source_duplicates
 * @property string|null $error
 * @property PullTrigger $trigger_type
 * @property PullOutcome|null $outcome
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $finished_at
 */
#[UseFactory(PullRunFactory::class)]
final class PullRun extends Model
{
    /** @use HasFactory<PullRunFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'source_id', 'triggered_by', 'trigger_type', 'started_at', 'finished_at', 'fetched', 'parsed', 'accepted',
        'flagged', 'quarantined', 'duplicates', 'cross_source_duplicates', 'error', 'outcome',
    ];

    /** Mirrors the column defaults, so a new model matches its stored row. */
    protected $attributes = ['fetched' => 0, 'parsed' => 0, 'accepted' => 0, 'flagged' => 0, 'quarantined' => 0, 'duplicates' => 0, 'cross_source_duplicates' => 0];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'source_id' => 'integer',
            'trigger_type' => PullTrigger::class,
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'fetched' => 'integer',
            'parsed' => 'integer',
            'accepted' => 'integer',
            'flagged' => 'integer',
            'quarantined' => 'integer',
            'outcome' => PullOutcome::class,
            'duplicates' => 'integer',
            'cross_source_duplicates' => 'integer',
        ];
    }

    /** @return BelongsTo<SubmissionSource, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(SubmissionSource::class, 'source_id');
    }

    /** @return BelongsTo<User, $this> */
    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }
}
