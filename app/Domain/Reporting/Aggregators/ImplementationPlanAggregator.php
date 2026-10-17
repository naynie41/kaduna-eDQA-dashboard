<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Aggregators;

use App\Domain\Reporting\DTOs\LgaRow;
use App\Domain\Reporting\DTOs\LgaScore;
use App\Domain\Reporting\DTOs\Plan;
use App\Domain\Reporting\DTOs\PlanRow;
use App\Domain\Reporting\DTOs\PlanSummary;
use App\Domain\Reporting\DTOs\ReportFilter;
use App\Domain\Reporting\Support\FacilityScoresSql;
use App\Domain\Reporting\Support\RoundSelection;
use App\Domain\Round\Models\Round;
use App\Domain\Scoring\Bands;
use App\Domain\Scoring\DTOs\ScoreValue;
use App\Domain\Scoring\Enums\Band;
use App\Domain\Scoring\Enums\Dimension;
use App\Domain\Scoring\Presentation;
use Illuminate\Container\Attributes\Config;
use Illuminate\Support\Facades\DB;

/**
 * The implementation plan (ARCHITECTURE.md §9.2.6), read-only until Phase 6. For each LGA in view
 * (LgaTableAggregator's rows, so the same figures as the LGA view):
 *
 * - a stored plan_actions row for the plan's round (the latest round in view) is shown as stored;
 * - else, an overall below the configured band's lower bound (edqa.plan.action_below, read from the
 *   rule version and compared on the displayed value, as bands are) gets the standard action for
 *   its weakest dimension, ties going to the first in alphabetical order, with the dimension's
 *   default owner and a review "after" the plan's round;
 * - else it is listed under "No action needed". An LGA with no score in view is in neither.
 *
 * Weakest LGA first. "Actions by type" counts the rows here, never in a component (hard rule 5).
 */
final class ImplementationPlanAggregator
{
    private const BANDS = ['strong', 'acceptable', 'review'];

    /** @param  array<string, string>  $owners  dimension => lang/en/plan.php owners key */
    public function __construct(
        private readonly LgaTableAggregator $lgas,
        private readonly RoundSelection $rounds,
        private readonly FacilityScoresSql $sql,
        #[Config('edqa.plan.action_below')] private readonly string $actionBelow,
        #[Config('edqa.plan.default_owners')] private readonly array $owners,
    ) {}

    public function plan(ReportFilter $filter): Plan
    {
        $rounds = $this->rounds->current($filter);
        $round = $rounds === [] ? null : $rounds[count($rounds) - 1];
        $rules = $this->sql->config($rounds);
        if ($round === null || $rules === null) {
            return new Plan(null, null, [], [], $this->summary([]));
        }

        $band = in_array($this->actionBelow, self::BANDS, true) ? $this->actionBelow : 'acceptable';
        $bound = $rules->bands[$band];
        $roundLabel = self::roundLabel($round);
        $stored = $this->stored($round->id);

        $actions = [];
        $clear = [];
        foreach ($this->lgas->rows($filter) as $lga) {
            if (isset($stored[$lga->lgaId])) {
                foreach ($stored[$lga->lgaId] as $row) {
                    $actions[] = new PlanRow($lga->lgaId, $lga->code, $lga->name, $lga->overall, $row['dimension'], $lga->{$row['dimension']->value},
                        $row['action'], $row['owner'], $row['due'], null, stored: true);
                }

                continue;
            }
            if ($lga->overall->value === null) {
                continue; // no score in view: nothing to plan
            }
            if (Presentation::round($lga->overall->value, Bands::DISPLAY_DP) >= $bound) {
                $clear[] = new LgaScore($lga->lgaId, $lga->code, $lga->name, $lga->facilities, $lga->overall);

                continue;
            }

            $weakest = self::weakest($lga);
            if ($weakest !== null) {
                $actions[] = new PlanRow($lga->lgaId, $lga->code, $lga->name, $lga->overall, $weakest, $lga->{$weakest->value},
                    self::action($weakest), $this->owner($weakest), null, $roundLabel, stored: false);
            }
        }

        usort($actions, fn (PlanRow $a, PlanRow $b): int => [$a->overall->value ?? INF, $a->name, $a->dimension->value] <=> [$b->overall->value ?? INF, $b->name, $b->dimension->value]);
        usort($clear, fn (LgaScore $a, LgaScore $b): int => $a->name <=> $b->name);

        return new Plan(
            round: $roundLabel,
            threshold: [
                'band' => $band,
                'label' => Band::from($band)->label(),
                'value' => ScoreValue::format($bound, floor($bound) === $bound ? 0 : 1),
            ],
            actions: $actions,
            clear: $clear,
            summary: $this->summary($actions),
        );
    }

    /** The lowest of the three dimensions; a tie goes to the first in alphabetical order. */
    private static function weakest(LgaRow $lga): ?Dimension
    {
        $weakest = null;
        foreach (Dimension::cases() as $dimension) { // availability, consistency, validity
            $value = $lga->{$dimension->value}->value;
            if ($value !== null && ($weakest === null || $value < $lga->{$weakest->value}->value)) {
                $weakest = $dimension;
            }
        }

        return $weakest;
    }

    /**
     * Stored plan rows for the round, by LGA, in dimension order.
     *
     * @return array<int, list<array{dimension: Dimension, action: string, owner: string, due: string}>>
     */
    private function stored(int $roundId): array
    {
        $byLga = [];
        $rows = DB::table('plan_actions')->where('round_id', $roundId)->orderBy('lga_id')->orderBy('dimension')
            ->get(['lga_id', 'dimension', 'action_text', 'assigned_to', 'due_date']);
        foreach ($rows as $row) {
            $byLga[(int) $row->lga_id][] = [
                'dimension' => Dimension::from((string) $row->dimension),
                'action' => (string) $row->action_text,
                'owner' => (string) $row->assigned_to,
                'due' => substr((string) $row->due_date, 0, 10),
            ];
        }

        return $byLga;
    }

    /**
     * @param  list<PlanRow>  $actions
     * @return list<PlanSummary>
     */
    private function summary(array $actions): array
    {
        return array_map(fn (Dimension $dimension): PlanSummary => new PlanSummary(
            $dimension,
            self::action($dimension),
            (string) __("plan.meanings.{$dimension->value}"),
            $this->owner($dimension),
            count(array_filter($actions, fn (PlanRow $row): bool => $row->dimension === $dimension)),
        ), Dimension::cases());
    }

    private static function action(Dimension $dimension): string
    {
        return (string) __("plan.actions.{$dimension->value}");
    }

    private function owner(Dimension $dimension): string
    {
        $key = $this->owners[$dimension->value] ?? '';

        return (string) __("plan.owners.{$key}");
    }

    private static function roundLabel(Round $round): string
    {
        return (string) __('reporting.round_label', ['quarter' => (string) $round->quarter, 'year' => (string) $round->year]);
    }
}
