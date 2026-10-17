<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Aggregators;

use App\Domain\Reporting\DTOs\MonthSlotPoint;
use App\Domain\Reporting\DTOs\ReportFilter;
use App\Domain\Reporting\RoundCache;
use App\Domain\Reporting\Support\FacilityScoresSql;
use App\Domain\Reporting\Support\RoundAggregatesView;
use App\Domain\Reporting\Support\RoundSelection;
use App\Domain\Reporting\Support\Scores;
use App\Domain\Reporting\Support\Weighting;
use App\Domain\Round\Models\Round;
use App\Domain\Round\MonthSlot;
use App\Domain\Scoring\DTOs\ScoreValue;
use App\Domain\Scoring\Enums\Dimension;
use App\Domain\Scoring\Presentation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The Visuals grouped bars (§9.2.5): for each quarter in view and each month slot, the three
 * dimensions and the overall (mean of the three that month), unweighted over facilities. Labelled
 * with the real month: slot 1 of Q2 is April. The Overall / Public / Private toggle is the owner
 * filter.
 */
final class MonthSlotAggregator
{
    public function __construct(
        private readonly RoundSelection $rounds,
        private readonly FacilityScoresSql $sql,
        private readonly RoundAggregatesView $view,
        private readonly RoundCache $cache,
    ) {}

    /** @return list<MonthSlotPoint> rounds in time order, slots 1–3 within each */
    public function series(ReportFilter $filter): array
    {
        Weighting::assertSupported();
        $rounds = $this->rounds->series($filter);

        return $this->cache->remember(RoundSelection::ids($rounds), self::class.':'.$filter->cacheKey(), function () use ($rounds, $filter): array {
            // The view has a row per round, scope, owner type and level, so it answers any number
            // of rounds (D-46); the SQL path recomputes every round from the scores.
            $byRound = $rounds !== [] && $this->view->usable() ? $this->fromView($rounds, $filter) : $this->fromSql($rounds, $filter);

            $points = [];
            foreach ($rounds as $round) {
                $rules = $this->sql->configFor($round);
                $row = $byRound->get($round->id);
                foreach ([1, 2, 3] as $slot) {
                    $points[] = new MonthSlotPoint(
                        roundId: $round->id,
                        year: $round->year,
                        quarter: $round->quarter,
                        slot: $slot,
                        month: MonthSlot::month($round->quarter, $slot),
                        label: MonthSlot::label($round->year, $round->quarter, $slot),
                        chartLabel: (string) __('reporting.month_chart_label', [
                            'month' => (string) __('reporting.months.'.MonthSlot::month($round->quarter, $slot)),
                            'quarter' => (string) $round->quarter,
                            'year' => (string) $round->year,
                        ]),
                        availability: Scores::of($row?->{"availability_m{$slot}"}, $rules),
                        consistency: Scores::of($row?->{"consistency_m{$slot}"}, $rules),
                        validity: Scores::of($row?->{"validity_m{$slot}"}, $rules),
                        overall: Scores::of($row?->{"overall_m{$slot}"}, $rules),
                    );
                }
            }

            return $points;
        });
    }

    /**
     * Per round: {dimension}_m{slot} and overall_m{slot} from the view rows the filter maps onto.
     *
     * @param  non-empty-list<Round>  $rounds
     * @return Collection<array-key, object>
     */
    private function fromView(array $rounds, ReportFilter $filter): Collection
    {
        [$scope, $scopeId, $scopeBindings] = RoundAggregatesView::scope($filter);
        $columns = [];
        foreach ([1, 2, 3] as $slot) {
            $columns[] = "availability_m{$slot}, consistency_m{$slot}, validity_m{$slot}, m{$slot} AS overall_m{$slot}";
        }
        $select = implode(', ', $columns);

        return collect(DB::select(<<<SQL
            SELECT round_id, {$select}
            FROM round_aggregates
            WHERE round_id = ANY(?::bigint[]) AND scope_type = ? AND scope_id = {$scopeId} AND owner_type = ? AND level = ?
            SQL, ['{'.implode(',', RoundSelection::ids($rounds)).'}', $scope, ...$scopeBindings, RoundAggregatesView::ownerType($filter), RoundAggregatesView::level($filter)]))->keyBy('round_id');
    }

    /**
     * @param  list<Round>  $rounds
     * @return Collection<array-key, object>
     */
    private function fromSql(array $rounds, ReportFilter $filter): Collection
    {
        [$cte, $bindings] = $this->sql->cte($rounds, $filter, perRound: true);

        $columns = [];
        foreach ([1, 2, 3] as $slot) {
            $columns[] = "avg(availability_m{$slot}) AS availability_m{$slot}, avg(consistency_m{$slot}) AS consistency_m{$slot}, "
                ."avg(validity_m{$slot}) AS validity_m{$slot}, avg(m{$slot}) AS overall_m{$slot}";
        }
        $select = implode(",\n                ", $columns);

        return collect(DB::select(<<<SQL
            {$cte}
            SELECT round_id,
                {$select}
            FROM facility_scores
            GROUP BY round_id
            SQL, $bindings))->keyBy('round_id');
    }

    /**
     * Each month chart's one-line takeaway (its Figure caption): the latest quarter's overall for
     * that slot, its change on the quarter before when there is one, and the lowest dimension.
     * A comparison of aggregates, so it is made here, never in a component (hard rule 5).
     *
     * @param  list<MonthSlotPoint>  $points  as series() returns them
     * @return array<int, ?string> by slot 1–3; null when the slot has no score in view
     */
    public function takeaways(array $points): array
    {
        $takeaways = [];
        foreach ([1, 2, 3] as $slot) {
            $scored = array_values(array_filter($points, fn (MonthSlotPoint $p): bool => $p->slot === $slot && $p->overall->value !== null));
            $latest = $scored[count($scored) - 1] ?? null;
            $takeaways[$slot] = $latest === null ? null : self::takeaway($latest, $scored[count($scored) - 2] ?? null);
        }

        return $takeaways;
    }

    private static function takeaway(MonthSlotPoint $latest, ?MonthSlotPoint $previous): string
    {
        $score = (float) $latest->overall->value;
        $sentence = (string) __('reporting.month.takeaway', ['month' => $latest->label, 'score' => $latest->overall->display]);
        if ($previous !== null) {
            $change = Presentation::round(abs($score - (float) $previous->overall->value), 1);
            $key = $change === 0.0 ? 'unchanged' : ($score > (float) $previous->overall->value ? 'up' : 'down');
            $sentence = (string) __("reporting.month.takeaway_{$key}", [
                'month' => $latest->label,
                'score' => $latest->overall->display,
                'change' => ScoreValue::format($change, 1),
                'previous' => $previous->label,
            ]);
        }

        // The lowest of the three dimensions; a tie goes to the first in the fixed order.
        $weakest = null;
        foreach ([Dimension::Availability, Dimension::Consistency, Dimension::Validity] as $dimension) {
            $value = $latest->{$dimension->value}->value;
            if ($value !== null && ($weakest === null || $value < $latest->{$weakest->value}->value)) {
                $weakest = $dimension;
            }
        }

        return $weakest === null ? $sentence : $sentence.' '.__('reporting.month.weakest', [
            'dimension' => $weakest->label(),
            'score' => $latest->{$weakest->value}->display,
        ]);
    }
}
