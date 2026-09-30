<?php

declare(strict_types=1);

namespace App\Domain\Facility\Models;

use App\Domain\Assessment\Models\Assessment;
use App\Domain\Facility\Enums\FacilityLevel;
use App\Domain\Facility\Enums\Ownership;
use App\Support\Audit\Audited;
use Database\Factories\FacilityFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A master-list facility: the validation authority. Deactivate, never delete.
 */
#[UseFactory(FacilityFactory::class)]
final class Facility extends Model
{
    use Audited;

    /** @use HasFactory<FacilityFactory> */
    use HasFactory;

    protected $fillable = [
        'code', 'name', 'ward_id', 'lga_id', 'level', 'ownership', 'is_active', 'lat', 'lng',
    ];

    /** Mirrors the column default, so a new model matches its stored row. */
    protected $attributes = ['is_active' => true];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'level' => FacilityLevel::class,
            'ownership' => Ownership::class,
            'is_active' => 'boolean',
            'lat' => 'decimal:6',
            'lng' => 'decimal:6',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Ward, $this> */
    public function ward(): BelongsTo
    {
        return $this->belongsTo(Ward::class);
    }

    /** @return BelongsTo<Lga, $this> */
    public function lga(): BelongsTo
    {
        return $this->belongsTo(Lga::class);
    }

    /** @return HasMany<Assessment, $this> */
    public function assessments(): HasMany
    {
        return $this->hasMany(Assessment::class);
    }

    protected function auditedAttributes(): array
    {
        return ['code', 'name', 'ward_id', 'lga_id', 'level', 'ownership', 'is_active', 'lat', 'lng'];
    }
}
