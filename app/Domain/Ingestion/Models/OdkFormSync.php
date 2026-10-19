<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Models;

use Carbon\CarbonImmutable;
use Database\Factories\OdkFormSyncFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * The pull cursor for one ODK form. last_pulled_at advances only after a whole page commits
 * (ARCHITECTURE.md §5.3). last_edit_at is the edits pass's cursor (D-51).
 *
 * @property CarbonImmutable|null $last_pulled_at
 * @property CarbonImmutable|null $last_edit_at
 */
#[UseFactory(OdkFormSyncFactory::class)]
final class OdkFormSync extends Model
{
    /** @use HasFactory<OdkFormSyncFactory> */
    use HasFactory;

    protected $fillable = [
        'project_id', 'form_id', 'last_pulled_at', 'last_instance_id', 'backfill_skip',
        'last_error', 'submission_count', 'last_edit_at',
    ];

    /** Mirrors the column defaults, so a new model matches its stored row. */
    protected $attributes = ['backfill_skip' => 0, 'submission_count' => 0];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'project_id' => 'integer',
            'backfill_skip' => 'integer',
            'submission_count' => 'integer',
            'last_pulled_at' => 'immutable_datetime',
            'last_edit_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
