<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Odk;

use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * One pull at a time (ARCHITECTURE.md §5.3): Cache::lock('odk-pull'), held for at most
 * lock_seconds. A second pull waits for the first to finish rather than pulling the same pages
 * twice; if it waits longer than $waitSeconds it gets LockTimeoutException.
 */
final class PullLock
{
    public const NAME = 'odk-pull';

    /**
     * @template T
     *
     * @param  Closure(): T  $pull
     * @return T
     *
     * @throws LockTimeoutException
     */
    public function run(Closure $pull, int $waitSeconds): mixed
    {
        $seconds = (int) config('edqa.odk.pull.lock_seconds', 900);

        return Cache::lock(self::NAME, $seconds)->block($waitSeconds, $pull);
    }
}
