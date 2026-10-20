<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Odk;

use App\Domain\Ingestion\Exceptions\BackfillRefused;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * One backfill at a time (ARCHITECTURE.md §5.6): Cache::lock('odk-backfill'), taken without
 * waiting. It is separate from PullLock so the scheduled pull keeps running during a backfill that
 * takes hours; the backfill takes PullLock only while it commits each page.
 */
final class BackfillLock
{
    public const NAME = 'odk-backfill';

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
