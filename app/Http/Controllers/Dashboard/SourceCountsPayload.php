<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Domain\Ingestion\SourceRegistry;
use App\Domain\Reporting\Aggregators\AssessmentsBySourceAggregator;
use App\Domain\Reporting\DTOs\ReportFilter;
use App\Domain\Reporting\DTOs\SourceCount;

/**
 * Monitor's "Assessments by source" (§9.2.4): the aggregator's counts (most first), each named
 * from the source registry, which Reporting may not read; then every other configured source
 * at 0, in config order. A source no longer in config keeps its row with a null key. Nothing is
 * counted here.
 */
final class SourceCountsPayload
{
    public function __construct(
        private readonly AssessmentsBySourceAggregator $aggregator,
        private readonly SourceRegistry $sources,
    ) {}

    /** @return list<array{key: ?string, name: string, assessments: int}> */
    public function build(ReportFilter $filter): array
    {
        $rows = array_map(function (SourceCount $count): array {
            $key = $this->sources->keyOf($count->sourceId);

            return ['key' => $key, 'name' => $key === null ? '' : $this->sources->name($key), 'assessments' => $count->assessments];
        }, $this->aggregator->counts($filter));

        $listed = array_column($rows, 'key');
        foreach ($this->sources->keys() as $key) {
            if (! in_array($key, $listed, true)) {
                $rows[] = ['key' => $key, 'name' => $this->sources->name($key), 'assessments' => 0];
            }
        }

        return $rows;
    }
}
