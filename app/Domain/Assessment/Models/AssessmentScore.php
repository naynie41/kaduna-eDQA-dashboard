<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Models;

use App\Domain\Scoring\Enums\Dimension;
use App\Domain\Scoring\Models\ScoringRuleVersion;
use App\Support\Audit\Audited;
use Database\Factories\AssessmentScoreFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A derived score for one (assessment, dimension, month slot) under one rule version. Null
 * when every item in the slot is N/A. Kept as a decimal string at full precision; rounding
 * happens only at presentation (CONVENTION.md §2.7).
 */
#[UseFactory(AssessmentScoreFactory::class)]
final class AssessmentScore extends Model
{
    use Audited;

    /** @use HasFactory<AssessmentScoreFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = ['assessment_id', 'dimension', 'month_slot', 'score', 'rule_version_id', 'computed_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'dimension' => Dimension::class,
            'month_slot' => 'integer',
            'score' => 'decimal:2',
            'computed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Assessment, $this> */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    /** @return BelongsTo<ScoringRuleVersion, $this> */
    public function ruleVersion(): BelongsTo
    {
        return $this->belongsTo(ScoringRuleVersion::class, 'rule_version_id');
    }

    protected function auditedAttributes(): array
    {
        return ['dimension', 'month_slot', 'score', 'rule_version_id'];
    }
}
