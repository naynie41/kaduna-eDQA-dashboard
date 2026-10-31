<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Actions;

use App\Domain\Ingestion\DTOs\BackfillSurvey;
use App\Domain\Ingestion\Exceptions\SourceException;
use App\Domain\Ingestion\FieldMap;
use App\Domain\Ingestion\SourceRegistry;
use App\Domain\Ingestion\SubmissionParser;
use App\Domain\Round\Models\Round;
use Closure;
use Illuminate\Support\Sleep;

/**
 * `edqa:backfill {source} --survey` (ARCHITECTURE.md §5.6): reads every submission the source's
 * form has, through its normaliser, writes nothing, and counts them per quarter and form version, so the historical rounds can be created
 * before the backfill. The quarter is the round the visit is held to, as ROUND_WINDOW decides it:
 * the quarter the form declares it reviews (D-71; the real Kobo form is filled in after the period
 * ends), else the visit's own quarter (D-28). Each row carries its visit dates (the start, or the
 * submission time when the start does not parse, as a local day), and a round whose window misses
 * some of them is reported: those visits would quarantine until the window covers them. A version with no field
 * map is counted under its own name and marked: its submissions will quarantine, never be read
 * through another version's map.
 */
final class SurveyBackfill
{
    public function __construct(
        private readonly SubmissionParser $parser,
        private readonly SourceRegistry $sources,
    ) {}

    /**
     * @param  (Closure(int $read, int $total): void)|null  $progress
     *
     * @throws SourceException
     */
    public function execute(string $source, ?int $throttle = null, ?Closure $progress = null): BackfillSurvey
    {
        if ($this->sources->problems($source) !== []) {
            throw $this->sources->notConfigured($source);
        }
        $adapter = $this->sources->adapter($source);
        $normaliser = $this->sources->normaliser($source);
        $top = $this->sources->pageSize($source);
        $throttle = max(0, $throttle ?? $this->sources->backfillThrottle($source));
        $counts = [];
        $visits = [];
        $skip = 0;

        while (true) {
            $page = $adapter->submissionsPage(null, $top, $skip);
            foreach ($page->rows as $row) {
                $parsed = $this->parser->parse($normaliser->normalise($row));
                $day = $parsed->visitDate();
                $declared = $parsed->roundYear !== null && $parsed->roundQuarter !== null;
                $year = $declared ? $parsed->roundYear : $day->year;
                $quarter = $declared ? $parsed->roundQuarter : $day->quarter;
                $key = "{$year}|{$quarter}|{$parsed->formVersion}";
                $counts[$key] = ($counts[$key] ?? 0) + 1;
                $visits[$key][] = $day->toDateString();
            }
            $skip += count($page->rows);
            if ($progress !== null) {
                $progress($skip, $page->totalCount);
            }
            if (! $page->hasMore || $page->rows === []) {
                break;
            }
            if ($throttle > 0) {
                Sleep::for($throttle)->seconds();
            }
        }

        $rounds = [];
        foreach (Round::query()->get(['year', 'quarter', 'status', 'window_start', 'window_end']) as $round) {
            $rounds["{$round->year}|{$round->quarter}"] = $round;
        }

        $rows = [];
        foreach ($counts as $key => $count) {
            [$year, $quarter, $version] = explode('|', $key, 3);
            $round = $rounds["{$year}|{$quarter}"] ?? null;
            $dates = $visits[$key];
            sort($dates);
            $window = $round === null ? null : [$round->window_start->toDateString(), $round->window_end->toDateString()];
            $outside = $window === null ? 0 : count(array_filter($dates, fn (string $date): bool => $date < $window[0] || $date > $window[1]));
            $rows[] = [
                'year' => (int) $year,
                'quarter' => (int) $quarter,
                'version' => $version,
                'mapped' => FieldMap::for($source, $version) !== null,
                'round' => $round?->status->value,
                'window' => $window,
                'first_visit' => $dates[0],
                'last_visit' => $dates[count($dates) - 1],
                'outside_window' => $outside,
                'submissions' => $count,
            ];
        }
        usort($rows, fn (array $a, array $b): int => [$a['year'], $a['quarter'], $a['version']] <=> [$b['year'], $b['quarter'], $b['version']]);

        return new BackfillSurvey($skip, $rows);
    }
}
