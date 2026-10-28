<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Actions;

use App\Domain\Ingestion\Contracts\SubmissionSource;
use App\Domain\Ingestion\DTOs\BackfillSurvey;
use App\Domain\Ingestion\FieldMap;
use App\Domain\Ingestion\Odk\OdkPayloadNormalizer;
use App\Domain\Ingestion\SubmissionParser;
use App\Domain\Round\Models\Round;
use Closure;
use Illuminate\Support\Sleep;

/**
 * `edqa:backfill --survey` (ARCHITECTURE.md §5.6): reads every submission the form has, writes
 * nothing, and counts them per quarter and form version, so the historical rounds can be created
 * before the backfill. The quarter is the visit's, as ROUND_WINDOW decides it (D-28): the start,
 * or the submission time when the start does not parse, as a local day. A version with no field
 * map is counted under its own name and marked: its submissions will quarantine, never be read
 * through another version's map.
 */
final class SurveyBackfill
{
    public function __construct(
        private readonly SubmissionSource $source,
        private readonly SubmissionParser $parser,
        private readonly OdkPayloadNormalizer $normaliser,
    ) {}

    /** @param  (Closure(int $read, int $total): void)|null  $progress */
    public function execute(?int $throttle = null, ?Closure $progress = null): BackfillSurvey
    {
        $top = max(1, (int) config('edqa.sources.odk.page_size', 500));
        $throttle = max(0, $throttle ?? (int) config('edqa.sources.odk.backfill_throttle_seconds', 5));
        $counts = [];
        $skip = 0;

        while (true) {
            $page = $this->source->submissionsPage(null, $top, $skip);
            foreach ($page->rows as $row) {
                $parsed = $this->parser->parse($this->normaliser->normalise($row));
                $day = $parsed->visitDate();
                $key = "{$day->year}|{$day->quarter}|{$parsed->formVersion}";
                $counts[$key] = ($counts[$key] ?? 0) + 1;
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
        foreach (Round::query()->get(['year', 'quarter', 'status']) as $round) {
            $rounds["{$round->year}|{$round->quarter}"] = $round->status->value;
        }

        $rows = [];
        foreach ($counts as $key => $count) {
            [$year, $quarter, $version] = explode('|', $key, 3);
            $rows[] = [
                'year' => (int) $year,
                'quarter' => (int) $quarter,
                'version' => $version,
                'mapped' => FieldMap::for(OdkPayloadNormalizer::SOURCE, $version) !== null,
                'round' => $rounds["{$year}|{$quarter}"] ?? null,
                'submissions' => $count,
            ];
        }
        usort($rows, fn (array $a, array $b): int => [$a['year'], $a['quarter'], $a['version']] <=> [$b['year'], $b['quarter'], $b['version']]);

        return new BackfillSurvey($skip, $rows);
    }
}
