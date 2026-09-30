<?php

declare(strict_types=1);

namespace App\Domain\Facility\Models;

use App\Domain\Plan\Models\PlanAction;
use Database\Factories\LgaFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One of the 23 LGAs. Seeded only; the app role cannot insert (CLAUDE.md hard rule 9), so it
 * is not audited.
 */
#[UseFactory(LgaFactory::class)]
final class Lga extends Model
{
    /** @use HasFactory<LgaFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = ['name', 'code'];

    /** @return HasMany<Ward, $this> */
    public function wards(): HasMany
    {
        return $this->hasMany(Ward::class);
    }

    /** @return HasMany<Facility, $this> */
    public function facilities(): HasMany
    {
        return $this->hasMany(Facility::class);
    }

    /** @return HasMany<PlanAction, $this> */
    public function planActions(): HasMany
    {
        return $this->hasMany(PlanAction::class);
    }
}
