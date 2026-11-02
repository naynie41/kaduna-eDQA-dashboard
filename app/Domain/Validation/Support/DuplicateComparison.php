<?php

declare(strict_types=1);

namespace App\Domain\Validation\Support;

use App\Domain\Ingestion\DTOs\ItemResponseData;
use App\Domain\Ingestion\DTOs\ParsedSubmission;
use App\Domain\Scoring\CurrentRuleVersion;
use App\Domain\Scoring\DTOs\ScoreValue;
use App\Domain\Scoring\Enums\Dimension;
use App\Domain\Scoring\ScoreCalculator;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The overall each side of a duplicate would score (ARCHITECTURE.md §5.8, Phase 5 step 2): the
 * accepted assessment from its stored item responses, the quarantined submission from its parsed
 * items, both through ScoreCalculator under the current rule version, so the two compare like for
 * like. Shown to the administrator choosing between them; never stored (CLAUDE.md hard rule 2:
 * scores come only from the pipeline).
 */
final class DuplicateComparison
{
    public function __construct(
        private readonly ScoreCalculator $calculator,
        private readonly CurrentRuleVersion $rules,
    ) {}

    /**
     * @param  list<int>  $assessmentIds
     * @return array<int, ScoreValue> assessment id => overall, one query for them all
     */
    public function assessed(array $assessmentIds): array
    {
        $items = [];
        foreach (DB::table('item_responses')->whereIn('assessment_id', $assessmentIds)->orderBy('id')->get(['assessment_id', 'dimension', 'month_slot', 'item_code', 'value', 'is_applicable']) as $row) {
            $items[(int) $row->assessment_id][] = new ItemResponseData(Dimension::from((string) $row->dimension), (int) $row->month_slot, (string) $row->item_code, (string) $row->value, (bool) $row->is_applicable);
        }

        $overalls = [];
        foreach ($assessmentIds as $id) {
            $overalls[$id] = $this->score($items[$id] ?? []);
        }

        return $overalls;
    }

    /** @param  array<string, mixed>  $parsed  quarantined_records.parsed */
    public function submission(array $parsed): ScoreValue
    {
        try {
            return $this->score(ParsedSubmission::fromArray($parsed)->items);
        } catch (Throwable) {
            return ScoreValue::fromNullable(null, $this->rules->config());
        }
    }

    /** @param  list<ItemResponseData>  $items */
    private function score(array $items): ScoreValue
    {
        $config = $this->rules->config();
        if ($config === null || $items === []) {
            return ScoreValue::fromNullable(null, $config);
        }
        try {
            return ScoreValue::fromNullable($this->calculator->calculate($items, $config)->overall, $config);
        } catch (Throwable) {
            return ScoreValue::fromNullable(null, $config); // a dimension with nothing to score
        }
    }
}
