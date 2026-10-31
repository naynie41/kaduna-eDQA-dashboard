<?php

declare(strict_types=1);

namespace App\Domain\Ingestion;

use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * One pull per source at a time (ARCHITECTURE.md §5.3): Cache::lock("pull:{source}"), held for at
 * most edqa.pull.lock_seconds. ODK and Kobo each have their own, so they pull in parallel; a
 * second pull of one source waits for the first rather than pulling the same pages twice, and
 * gets LockTimeoutException after $waitSeconds. The reconcile sweep and each backfill page take
 * the same lock, since they write the same submissions.
 */
final class PullLock
{
    public static function name(string $source): string
    {
        return "pull:{$source}";
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $pull
     * @return T
     *
     * @throws LockTimeoutException
     */
    public function run(string $source, Closure $pull, int $waitSeconds): mixed
    {
        $seconds = (int) config('edqa.pull.lock_seconds', 900);

        return Cache::lock(self::name($source), $seconds)->block($waitSeconds, $pull);
    }

    /** How long a caller waits for a busy pull of the source (edqa.pull.lock_wait_seconds). */
    public static function waitSeconds(): int
    {
        return (int) config('edqa.pull.lock_wait_seconds', 600);
    }
}
