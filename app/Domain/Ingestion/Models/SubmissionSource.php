<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Models;

use App\Domain\Ingestion\Enums\SourceType;
use App\Support\Audit\Audited;
use Carbon\CarbonImmutable;
use Database\Factories\SubmissionSourceFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A field-collection source (ARCHITECTURE.md §5.0): a stable ID for the rows that come from it.
 * Config (edqa.sources) says what it is and how to reach it; this row only names it, and
 * edqa:sources:sync keeps it in step. Credentials are never stored here. Audited: switching a
 * source on or off changes what reaches the dashboard.
 *
 * @property string $key
 * @property SourceType $type
 * @property string $name
 * @property bool $is_enabled
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[UseFactory(SubmissionSourceFactory::class)]
final class SubmissionSource extends Model
{
    use Audited;

    /** @use HasFactory<SubmissionSourceFactory> */
    use HasFactory;

    protected $fillable = ['key', 'type', 'name', 'is_enabled'];

    protected $attributes = ['is_enabled' => true];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => SourceType::class,
            'is_enabled' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<Submission, $this> */
    public function submissions(): HasMany
    {
        return $this->hasMany(Submission::class, 'source_id');
    }

    /** @return HasOne<SourceSync, $this> */
    public function sync(): HasOne
    {
        return $this->hasOne(SourceSync::class, 'source_id');
    }

    protected function auditedAttributes(): array
    {
        return ['key', 'type', 'name', 'is_enabled'];
    }
}
