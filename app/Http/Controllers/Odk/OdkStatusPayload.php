<?php

declare(strict_types=1);

namespace App\Http\Controllers\Odk;

use App\Domain\Ingestion\Queries\OdkStatusQuery;
use App\Domain\Reporting\Aggregators\ShellStatusAggregator;
use App\Domain\Validation\Queries\QuarantineQuery;

/**
 * The ODK status (ARCHITECTURE.md §8): GET /api/odk/status and the ODK pull page's first paint
 * send exactly this. Each part from the domain that owns it; nothing is counted here.
 */
final class OdkStatusPayload
{
    public function __construct(
        private readonly OdkStatusQuery $odk,
        private readonly ShellStatusAggregator $shell,
        private readonly QuarantineQuery $quarantine,
    ) {}

    /** @return array<string, mixed> */
    public function build(): array
    {
        $rounds = $this->shell->openRounds();
        $quarantined = $this->quarantine->openCountsByRound(array_map(fn (array $round): int => $round['id'], $rounds));

        return [
            ...$this->odk->status(),
            'sync' => $this->shell->sync(),
            'open_rounds' => array_map(fn (array $round): array => [...$round, 'quarantined' => $quarantined[$round['id']] ?? 0], $rounds),
        ];
    }
}
