<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Models;

use App\Domain\Ingestion\Enums\PullOutcome;
use App\Domain\Ingestion\Enums\PullTrigger;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\OdkPullRunFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One ODK pull: powers the pull history and the live progress on the ODK pull page.
 * Manual pulls are audited by the action that starts them (SECURITY.md §4), not here.
 *
 * @property PullTrigger $trigger_type
 * @property PullOutcome|null $outcome
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $finished_at
 */
#[UseFactory(OdkPullRunFactory::class)]
final class OdkPullRun extends Model
{
    /** @use HasFactory<OdkPullRunFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'triggered_by', 'trigger_type', 'started_at', 'finished_at', 'fetched', 'accepted',
        'flagged', 'quarantined', 'duplicates', 'error', 'outcome',
    ];

    /** Mirrors the column defaults, so a new model matches its stored row. */
    protected $attributes = ['fetched' => 0, 'accepted' => 0, 'flagged' => 0, 'quarantined' => 0, 'duplicates' => 0];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'trigger_type' => PullTrigger::class,
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'fetched' => 'integer',
            'accepted' => 'integer',
            'flagged' => 'integer',
            'quarantined' => 'integer',
            'outcome' => PullOutcome::class,
            'duplicates' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }
}
