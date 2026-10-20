<?php

declare(strict_types=1);

namespace App\Http\Controllers\Odk;

use App\Domain\Ingestion\Queries\OdkStatusQuery;
use App\Domain\Reporting\Aggregators\ShellStatusAggregator;
use App\Domain\Validation\Queries\QuarantineQuery;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/odk/status (ARCHITECTURE.md §8): is ODK Central configured and answering, the sync
 * state the status bar shows (last success, minutes since, last error), the cursors, the pull in
 * progress, and each open round's accepted, flagged and quarantined counts. Each part comes from
 * the domain that owns it; nothing is counted here.
 */
final class OdkStatusController extends Controller
{
    public function __invoke(OdkStatusQuery $odk, ShellStatusAggregator $shell, QuarantineQuery $quarantine): JsonResponse
    {
        $rounds = $shell->openRounds();
        $quarantined = $quarantine->openCountsByRound(array_map(fn (array $round): int => $round['id'], $rounds));

        return response()->json(['data' => [
            ...$odk->status(),
            'sync' => $shell->sync(),
            'open_rounds' => array_map(fn (array $round): array => [...$round, 'quarantined' => $quarantined[$round['id']] ?? 0], $rounds),
        ]]);
    }
}
