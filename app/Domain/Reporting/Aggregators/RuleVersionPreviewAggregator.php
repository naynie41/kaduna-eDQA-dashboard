<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Aggregators;

use App\Domain\Ingestion\DTOs\ItemResponseData;
use App\Domain\Reporting\DTOs\RoundPreview;
use App\Domain\Reporting\DTOs\RuleVersionPreview;
use App\Domain\Round\Enums\RoundStatus;
use App\Domain\Round\Models\Round;
use App\Domain\Scoring\DTOs\RuleConfig;
use App\Domain\Scoring\DTOs\ScoreValue;
use App\Domain\Scoring\Enums\Dimension;
use App\Domain\Scoring\Exceptions\IncompleteAssessmentException;
use App\Domain\Scoring\Exceptions\InvalidRuleConfig;
use App\Domain\Scoring\Models\ScoringRuleVersion;
use App\Domain\Scoring\RuleVersionForRound;
use App\Domain\Scoring\ScoreCalculator;
use Illuminate\Support\Facades\DB;

/**
 * The publish preview (ARCHITECTURE.md §7 Rule versions): for every OPEN round, every assessment
 * is scored twice in memory from its stored item responses, under the round's current version and
 * under the draft, and the results rolled up as the dashboard does (facility = mean of its
 * assessments; LGA and state = unweighted mean of facility scores). Closed rounds keep their
 * pinned version (D-32), so they are not affected and not listed. Reads only, in chunks.
 *
 * Stored answers are either outcomes (pass/fail/na, from processing) or raw choices (yes/no/na,
 * from the factories and demo seed; Q-25); raw ones go through each version's choice_map, and an
 * item is applicable when its mapped outcome is not N/A. An assessment that cannot be scored
 * under either version (incomplete, or an answer outside the choice map) is counted as skipped.
 */
final class RuleVersionPreviewAggregator
{
    public const CHUNK = 500;

    public function __construct(
        private readonly ScoreCalculator $calculator,
        private readonly RuleVersionForRound $versions,
    ) {}

    public function preview(ScoringRuleVersion $draft, int $chunkSize = self::CHUNK): RuleVersionPreview
    {
        $draftRules = RuleConfig::fromVersion($draft);
        $rounds = Round::query()->where('status', RoundStatus::Open)->orderBy('year')->orderBy('quarter')->get();

        $previews = [];
        foreach ($rounds as $round) {
            $previews[] = $this->previewRound($round, $this->versions->config($round), $draftRules, $chunkSize);
        }

        return new RuleVersionPreview($draft->id, $previews);
    }

    private function previewRound(Round $round, RuleConfig $current, RuleConfig $draft, int $chunkSize): RoundPreview
    {
        /** @var array<int, array{code: string, lga_id: int, before: list<float>, after: list<float>}> $facilities */
        $facilities = [];
        $assessments = 0;
        $skipped = 0;

        DB::table('assessments')
            ->join('facilities', 'facilities.id', '=', 'assessments.facility_id')
            ->where('assessments.round_id', $round->id)
            ->orderBy('assessments.id')
            ->select(['assessments.id', 'assessments.facility_id', 'facilities.code', 'facilities.lga_id'])
            ->chunk($chunkSize, function ($rows) use (&$facilities, &$assessments, &$skipped, $current, $draft): void {
                $items = DB::table('item_responses')
                    ->whereIn('assessment_id', $rows->pluck('id')->all())
                    ->get(['assessment_id', 'dimension', 'month_slot', 'item_code', 'value'])
                    ->groupBy('assessment_id');

                foreach ($rows as $row) {
                    $assessments++;
                    $stored = $items->get($row->id, collect())->all();

                    try {
                        $before = $this->calculator->calculate($this->toItems($stored, $current), $current)->overall;
                        $after = $this->calculator->calculate($this->toItems($stored, $draft), $draft)->overall;
                    } catch (IncompleteAssessmentException|InvalidRuleConfig) {
                        $skipped++;

                        continue;
                    }

                    $id = (int) $row->facility_id;
                    $facilities[$id] ??= ['code' => (string) $row->code, 'lga_id' => (int) $row->lga_id, 'before' => [], 'after' => []];
                    $facilities[$id]['before'][] = $before;
                    $facilities[$id]['after'][] = $after;
                }
            });

        $facilityChanges = [];
        $byLga = [];
        $stateBefore = [];
        $stateAfter = [];
        foreach ($facilities as $id => $facility) {
            $before = self::mean($facility['before']);
            $after = self::mean($facility['after']);
            $stateBefore[] = $before;
            $stateAfter[] = $after;
            $byLga[$facility['lga_id']]['before'][] = $before;
            $byLga[$facility['lga_id']]['after'][] = $after;

            $change = self::bandChange($before, $after, $current, $draft);
            if ($change !== null) {
                $facilityChanges[] = ['facility_id' => $id, 'code' => $facility['code'], 'lga_id' => $facility['lga_id'], ...$change];
            }
        }

        return new RoundPreview(
            roundId: $round->id,
            year: $round->year,
            quarter: $round->quarter,
            assessments: $assessments,
            skipped: $skipped,
            facilitiesChangingBand: $facilityChanges,
            lgasChangingBand: $this->lgaChanges($byLga, $current, $draft),
            stateOverallBefore: ScoreValue::fromNullable($stateBefore === [] ? null : self::mean($stateBefore), $current),
            stateOverallAfter: ScoreValue::fromNullable($stateAfter === [] ? null : self::mean($stateAfter), $draft),
        );
    }

    /**
     * @param  list<object>  $stored  item_responses rows
     * @return list<ItemResponseData>
     */
    private function toItems(array $stored, RuleConfig $rules): array
    {
        return array_map(function (object $row) use ($rules): ItemResponseData {
            $outcome = $rules->outcome((string) $row->value);

            return new ItemResponseData(Dimension::from((string) $row->dimension), (int) $row->month_slot, (string) $row->item_code, $outcome, $outcome !== 'na');
        }, $stored);
    }

    /**
     * @param  array<int, array{before: list<float>, after: list<float>}>  $byLga
     * @return list<array{lga_id: int, code: string, name: string, before: ScoreValue, after: ScoreValue}>
     */
    private function lgaChanges(array $byLga, RuleConfig $current, RuleConfig $draft): array
    {
        $changes = [];
        foreach ($byLga as $lgaId => $scores) {
            $change = self::bandChange(self::mean($scores['before']), self::mean($scores['after']), $current, $draft);
            if ($change !== null) {
                $changes[$lgaId] = $change;
            }
        }
        if ($changes === []) {
            return [];
        }

        $lgas = DB::table('lgas')->whereIn('id', array_keys($changes))->orderBy('name')->get(['id', 'code', 'name']);

        return array_values($lgas->map(fn (object $lga): array => [
            'lga_id' => (int) $lga->id,
            'code' => (string) $lga->code,
            'name' => (string) $lga->name,
            ...$changes[(int) $lga->id],
        ])->all());
    }

    /**
     * The before and after scores when the displayed band changes (Bands::classify, as shown);
     * null when it stays the same.
     *
     * @return array{before: ScoreValue, after: ScoreValue}|null
     */
    private static function bandChange(float $before, float $after, RuleConfig $current, RuleConfig $draft): ?array
    {
        $beforeScore = ScoreValue::from($before, $current);
        $afterScore = ScoreValue::from($after, $draft);

        return $beforeScore->band === $afterScore->band ? null : ['before' => $beforeScore, 'after' => $afterScore];
    }

    /** @param  list<float>  $values  non-empty */
    private static function mean(array $values): float
    {
        return array_sum($values) / count($values);
    }
}
