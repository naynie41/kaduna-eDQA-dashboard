<?php

declare(strict_types=1);

namespace App\Domain\Facility\Models;

use App\Support\Audit\Audited;
use Database\Factories\WardFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[UseFactory(WardFactory::class)]
final class Ward extends Model
{
    use Audited;

    /** @use HasFactory<WardFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = ['lga_id', 'name', 'code'];

    /** @return BelongsTo<Lga, $this> */
    public function lga(): BelongsTo
    {
        return $this->belongsTo(Lga::class);
    }

    /** @return HasMany<Facility, $this> */
    public function facilities(): HasMany
    {
        return $this->hasMany(Facility::class);
    }

    protected function auditedAttributes(): array
    {
        return ['lga_id', 'name', 'code'];
    }
}
