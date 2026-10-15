<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Reporting\Aggregators\DimensionAggregator;
use App\Domain\Reporting\Aggregators\LgaTableAggregator;
use App\Domain\Reporting\Aggregators\OwnerSplitAggregator;
use App\Domain\Reporting\Aggregators\QuarterTrendAggregator;
use App\Domain\Reporting\Aggregators\StateSummaryAggregator;
use App\Domain\Reporting\DTOs\GroupScores;
use App\Domain\Reporting\DTOs\LgaRow;
use App\Domain\Reporting\DTOs\ReportFilter;
use App\Domain\Reporting\DTOs\TrendPoint;
use App\Domain\Round\Models\Round;
use App\Domain\Scoring\DTOs\ScoreValue;
use App\Domain\Scoring\Enums\Dimension;
use App\Domain\Scoring\Models\ScoringRuleVersion;
use App\Domain\Scoring\RuleVersionForRound;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Prints the figures the dashboard will show, straight from the Aggregators (the only place a
 * number is computed, hard rule 5), so they can be checked without a UI: state, Public and
 * Private, and every LGA, each score as displayed with its band. One round (the latest by
 * default), or every round with --round=all after the quarter trend.
 */
final class ReportSummary extends Command
{
    protected $signature = 'edqa:report:summary
        {--round= : A round id, a quarter such as 2026Q2, or "all" (default: the latest round)}
        {--lga= : One LGA, by code}';

    protected $description = 'Print state, owner and LGA figures from the aggregators';

    public function handle(
        StateSummaryAggregator $state,
        DimensionAggregator $dimensions,
        OwnerSplitAggregator $owners,
        LgaTableAggregator $lgas,
        QuarterTrendAggregator $trend,
        RuleVersionForRound $versions,
    ): int {
        $lga = $this->option('lga');
        $lga = is_string($lga) && $lga !== '' ? $lga : null;
        try {
            ReportFilter::fromArray(['lga' => $lga]);
        } catch (ValidationException) {
            $this->error((string) __('reporting.summary.unknown_lga', ['lga' => (string) $lga]));

            return self::FAILURE;
        }

        $rounds = $this->rounds();
        if ($rounds === null) {
            return self::FAILURE;
        }

        if ($this->option('round') === 'all') {
            $this->trendTable($trend->trend(new ReportFilter(lga: $lga)));
        }

        foreach ($rounds as $round) {
            $filter = new ReportFilter(year: $round->year, quarter: $round->quarter, lga: $lga);
            $summary = $state->summary($filter);
            $dims = $dimensions->dimensions($filter);
            $split = $owners->split($filter);
            $version = ScoringRuleVersion::query()->find($versions->id($round))?->version;

            $this->newLine();
            $this->info((string) __('reporting.summary.round', [
                'round' => (string) __('reporting.round_label', ['quarter' => (string) $round->quarter, 'year' => (string) $round->year]),
                'status' => $round->status->value,
                'version' => (string) $version,
            ]));
            $this->line((string) __('reporting.summary.state_line', [
                'overall' => self::cell($summary->overall),
                'facilities' => (string) $summary->facilitiesVisited,
                'lgas' => (string) $summary->lgasCovered,
                'strong' => (string) $summary->facilitiesStrong,
                'weak' => (string) $summary->facilitiesWeak,
            ]));
            if ($dims->weakest !== null) {
                $this->line((string) __('reporting.summary.weakest', ['dimension' => $dims->weakest->label()]));
            }

            $this->table(self::headers('scope'), [
                [__('reporting.summary.state'), $summary->facilitiesVisited, self::cell($dims->availability), self::cell($dims->consistency), self::cell($dims->validity), self::cell($dims->overall)],
                self::groupRow((string) __('reporting.summary.public'), $split->public),
                self::groupRow((string) __('reporting.summary.private'), $split->private),
            ]);

            $this->table(self::headers('lga'), array_map(fn (LgaRow $row): array => [
                $row->name, $row->facilities, self::cell($row->availability), self::cell($row->consistency), self::cell($row->validity), self::cell($row->overall),
            ], $lgas->rows($filter)));
        }

        return self::SUCCESS;
    }

    /** @return Collection<int, Round>|null */
    private function rounds(): ?Collection
    {
        $option = $this->option('round');
        $all = Round::query()->orderBy('year')->orderBy('quarter')->get();
        if ($all->isEmpty()) {
            $this->error((string) __('reporting.summary.no_rounds'));

            return null;
        }

        $match = match (true) {
            $option === null || $option === '' => $all->slice(-1)->values(),
            $option === 'all' => $all,
            ctype_digit((string) $option) => $all->where('id', (int) $option)->values(),
            preg_match('/^(\d{4})\s*-?\s*Q([1-4])$/i', (string) $option, $m) === 1 => $all->where('year', (int) $m[1])->where('quarter', (int) $m[2])->values(),
            default => collect(),
        };

        if ($match->isEmpty()) {
            $this->error((string) __('reporting.summary.unknown_round', ['round' => (string) $option]));

            return null;
        }

        return $match;
    }

    /** @param  list<TrendPoint>  $points */
    private function trendTable(array $points): void
    {
        $this->newLine();
        $this->info((string) __('reporting.summary.trend'));
        $this->table(
            ['', __('reporting.summary.overall'), __('reporting.summary.public'), __('reporting.summary.private')],
            array_map(fn (TrendPoint $p): array => [$p->label, self::cell($p->overall), self::cell($p->public), self::cell($p->private)], $points),
        );
    }

    /** @return list<string> */
    private static function headers(string $first): array
    {
        return [
            (string) __("reporting.summary.{$first}"),
            (string) __('reporting.summary.facilities'),
            Dimension::Availability->label(),
            Dimension::Consistency->label(),
            Dimension::Validity->label(),
            (string) __('reporting.summary.overall'),
        ];
    }

    /** @return list<int|string> */
    private static function groupRow(string $label, GroupScores $group): array
    {
        return [$label, $group->facilities, self::cell($group->availability), self::cell($group->consistency), self::cell($group->validity), self::cell($group->overall)];
    }

    /** As the dashboard shows it: the 1 dp display and its band (Q-09, Q-26). */
    private static function cell(ScoreValue $score): string
    {
        return "{$score->display} {$score->bandLabel}";
    }
}
