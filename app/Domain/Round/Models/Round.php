<?php

declare(strict_types=1);

namespace App\Domain\Round\Models;

use App\Domain\Assessment\Models\Assessment;
use App\Domain\Plan\Models\PlanAction;
use App\Domain\Round\Enums\RoundStatus;
use App\Domain\Validation\Models\QuarantinedRecord;
use App\Support\Audit\Audited;
use Carbon\CarbonImmutable;
use Database\Factories\RoundFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One quarter's assessment window: open → closed (closed = published, D-07).
 *
 * @property CarbonImmutable $window_start
 * @property CarbonImmutable $window_end
 */
#[UseFactory(RoundFactory::class)]
final class Round extends Model
{
    use Audited;

    /** @use HasFactory<RoundFactory> */
    use HasFactory;

    protected $fillable = [
        'year', 'quarter', 'window_start', 'window_end', 'status', 'closed_at', 'reopened_reason',
    ];

    /** Mirrors the column default, so a new model matches its stored row. */
    protected $attributes = ['status' => 'open'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'quarter' => 'integer',
            'window_start' => 'immutable_date',
            'window_end' => 'immutable_date',
            'status' => RoundStatus::class,
            'closed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<Assessment, $this> */
    public function assessments(): HasMany
    {
        return $this->hasMany(Assessment::class);
    }

    /** @return HasMany<PlanAction, $this> */
    public function planActions(): HasMany
    {
        return $this->hasMany(PlanAction::class);
    }

    /** @return HasMany<QuarantinedRecord, $this> */
    public function quarantinedRecords(): HasMany
    {
        return $this->hasMany(QuarantinedRecord::class);
    }

    protected function auditedAttributes(): array
    {
        return ['year', 'quarter', 'window_start', 'window_end', 'status', 'closed_at', 'reopened_reason'];
    }
}
