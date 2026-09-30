<?php

declare(strict_types=1);

namespace App\Domain\Plan\Models;

use App\Domain\Facility\Models\Lga;
use App\Domain\Plan\Enums\PlanActionStatus;
use App\Domain\Round\Models\Round;
use App\Domain\Scoring\Enums\Dimension;
use App\Support\Audit\Audited;
use Database\Factories\PlanActionFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An implementation-plan row: one action per round, LGA and dimension, with an editable owner,
 * due date and status (D-10).
 */
#[UseFactory(PlanActionFactory::class)]
final class PlanAction extends Model
{
    use Audited;

    /** @use HasFactory<PlanActionFactory> */
    use HasFactory;

    protected $fillable = ['round_id', 'lga_id', 'dimension', 'action_text', 'assigned_to', 'due_date', 'status', 'notes'];

    /** Mirrors the column default, so a new model matches its stored row. */
    protected $attributes = ['status' => 'not_started'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'dimension' => Dimension::class,
            'status' => PlanActionStatus::class,
            'due_date' => 'immutable_date',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Round, $this> */
    public function round(): BelongsTo
    {
        return $this->belongsTo(Round::class);
    }

    /** @return BelongsTo<Lga, $this> */
    public function lga(): BelongsTo
    {
        return $this->belongsTo(Lga::class);
    }

    protected function auditedAttributes(): array
    {
        return ['action_text', 'assigned_to', 'due_date', 'status', 'notes'];
    }
}
