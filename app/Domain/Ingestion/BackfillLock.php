<?php

declare(strict_types=1);

namespace App\Domain\Ingestion;

use App\Domain\Ingestion\Exceptions\BackfillRefused;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * One backfill at a time, of any source (ARCHITECTURE.md §5.6: run one source at a time):
 * Cache::lock('backfill'), taken without waiting. It is apart from PullLock so the scheduled pulls
 * keep running during a backfill that takes hours; the backfill takes its source's PullLock only
 * while it commits each page. Between two chunks on the queue nothing holds it, so
 * BackfillSubmissions also refuses while another source's backfill run is still open.
 */
final class BackfillLock
{
    public const NAME = 'backfill';

    /**
     * @template T
     *
     * @param  Closure(): T  $backfill
     * @return T
     *
     * @throws BackfillRefused
     */
    public function run(Closure $backfill, int $seconds): mixed
    {
        $lock = Cache::lock(self::NAME, max(60, $seconds));
        if (! $lock->get()) {
            throw BackfillRefused::alreadyRunning();
        }
        try {
            return $backfill();
        } finally {
            $lock->release();
        }
    }
}
