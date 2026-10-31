<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sources;

use App\Domain\Ingestion\Aggregators\SourceStatusAggregator;
use App\Domain\Ingestion\Queries\SourceStatusQuery;
use App\Domain\Ingestion\SourceRegistry;

/**
 * Every source in config order, as GET /api/sources and the Data sources page show it: its
 * ingestion status (SourceStatusQuery: configured, the env keys missing, enabled, reachable,
 * cursor, the pull and sweep in progress, the last pull), its sync state
 * (SourceStatusAggregator) and its own counts in each open round. Each part from the class that
 * owns it; nothing is counted here.
 */
final class SourcesPayload
{
    public function __construct(
        private readonly SourceRegistry $sources,
        private readonly SourceStatusQuery $query,
        private readonly SourceStatusAggregator $status,
    ) {}

    /** @return list<array<string, mixed>> */
    public function build(): array
    {
        return array_map(fn (string $key): array => [
            ...$this->query->status($key),
            'status' => $this->status->source($key),
            'open_rounds' => $this->status->openRounds($key),
        ], $this->sources->keys());
    }
}
