<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Models;

use App\Domain\Scoring\Enums\Dimension;
use App\Support\Audit\Audited;
use Database\Factories\ItemResponseFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One scored question's answer. Scores are derived from these, never accepted from input
 * (CLAUDE.md hard rule 2).
 */
#[UseFactory(ItemResponseFactory::class)]
final class ItemResponse extends Model
{
    use Audited;

    /** @use HasFactory<ItemResponseFactory> */
    use HasFactory;

    /**
     * SECURITY.md §4 audits item responses for corrections: updates and deletes only. Logging
     * every ingested answer would add ~18 audit rows per submission.
     *
     * @var list<string>
     */
    protected static $recordEvents = ['updated', 'deleted'];

    public $timestamps = false;

    protected $fillable = ['assessment_id', 'dimension', 'month_slot', 'item_code', 'value', 'is_applicable'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'dimension' => Dimension::class,
            'month_slot' => 'integer',
            'is_applicable' => 'boolean',
        ];
    }

    /** @return BelongsTo<Assessment, $this> */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    protected function auditedAttributes(): array
    {
        return ['dimension', 'month_slot', 'item_code', 'value', 'is_applicable'];
    }
}
