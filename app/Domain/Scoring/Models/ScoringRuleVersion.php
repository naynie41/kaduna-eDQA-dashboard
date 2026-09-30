<?php

declare(strict_types=1);

namespace App\Domain\Scoring\Models;

use App\Domain\Assessment\Models\AssessmentScore;
use App\Models\User;
use App\Support\Audit\Audited;
use Database\Factories\ScoringRuleVersionFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Versioned scoring config: { bands, item_weights, na_policy, choice_map }. Draft until
 * published_at; the current version is the highest published one (ARCHITECTURE.md §7).
 */
#[UseFactory(ScoringRuleVersionFactory::class)]
final class ScoringRuleVersion extends Model
{
    use Audited;

    /** @use HasFactory<ScoringRuleVersionFactory> */
    use HasFactory;

    protected $fillable = ['version', 'config', 'created_by', 'published_at', 'published_reason'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'config' => 'array',
            'published_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<AssessmentScore, $this> */
    public function scores(): HasMany
    {
        return $this->hasMany(AssessmentScore::class, 'rule_version_id');
    }

    protected function auditedAttributes(): array
    {
        return ['version', 'config', 'published_at', 'published_reason'];
    }
}
