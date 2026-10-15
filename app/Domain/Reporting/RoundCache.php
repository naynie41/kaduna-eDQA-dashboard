<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Domain\Facility\Enums\FacilityLevel;
use App\Domain\Facility\Enums\Ownership;
use App\Domain\Scoring\DTOs\ScoreValue;
use App\Domain\Scoring\Enums\Band;
use App\Domain\Scoring\Enums\Dimension;
use Closure;
use Illuminate\Cache\TaggableStore;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * Aggregator results, cached per round and flushed per round (ARCHITECTURE.md §7). Hides the
 * difference between a store with tags (Redis, array: tags 'edqa' and 'round:{id}') and one
 * without (database or file, which production may use, DEPLOY.md §9: a generation number per
 * round is folded into the key, and flushing bumps it).
 */
final class RoundCache
{
    public const TTL = 21_600; // six hours: a safety net; flushes do the real work

    /**
     * The only classes the cache may unserialize (config cache.serializable_classes): the
     * aggregators' readonly DTOs and the enums inside them, none with magic methods, so the cache
     * cannot be turned into a gadget chain. Never an Eloquent model: RoundSelection caches ids.
     * SerializingCacheTest fails if an aggregator returns anything not listed here.
     */
    public const SERIALIZABLE = [
        DTOs\AssessmentWindow::class,
        DTOs\Coverage::class,
        DTOs\DimensionScores::class,
        DTOs\FacilityPage::class,
        DTOs\FacilityRow::class,
        DTOs\GroupScores::class,
        DTOs\LevelCoverage::class,
        DTOs\LgaCount::class,
        DTOs\LgaRow::class,
        DTOs\LgaScore::class,
        DTOs\MonthSlotPoint::class,
        DTOs\OwnerSplit::class,
        DTOs\StateSummary::class,
        DTOs\TrendPoint::class,
        DTOs\WardScore::class,
        Enums\Metric::class,
        ScoreValue::class,
        Band::class,
        Dimension::class,
        FacilityLevel::class,
        Ownership::class,
    ];

    /**
     * Bump when a cached DTO changes shape (a property added or renamed). Every key carries it,
     * so a deploy never serves an object cached by the previous code: an older StateSummary
     * would unserialize without its new properties (seen in Phase 4 step 2 as "NaN" thresholds).
     */
    public const SCHEMA = 2;

    private const PREFIX = 'edqa:reporting:v'.self::SCHEMA.':';

    /**
     * @template T
     *
     * @param  list<int>  $roundIds  every round the result depends on
     * @param  Closure(): T  $compute
     * @return T
     */
    public function remember(array $roundIds, string $key, Closure $compute): mixed
    {
        $roundIds = array_values(array_unique($roundIds));
        sort($roundIds);

        if ($this->tagged()) {
            return Cache::tags(['edqa', ...array_map(fn (int $id): string => "round:{$id}", $roundIds)])
                ->remember(self::PREFIX.$key, self::TTL, $compute);
        }

        $generations = array_map(fn (int $id): string => "{$id}g".$this->generation("round:{$id}"), $roundIds);
        $versioned = self::PREFIX.'all'.$this->generation('all').':'.implode('-', $generations).':'.md5($key);

        return $this->store()->remember($versioned, self::TTL, $compute);
    }

    /**
     * Results that depend on rounds as a whole (which rounds exist), flushed by flushAll().
     *
     * @template T
     *
     * @param  Closure(): T  $compute
     * @return T
     */
    public function rememberGlobal(string $key, Closure $compute): mixed
    {
        if ($this->tagged()) {
            return Cache::tags(['edqa'])->remember(self::PREFIX.$key, self::TTL, $compute);
        }

        return $this->store()->remember(self::PREFIX.'all'.$this->generation('all').':'.md5($key), self::TTL, $compute);
    }

    public function flushRound(int $roundId): void
    {
        if ($this->tagged()) {
            Cache::tags(["round:{$roundId}"])->flush();

            return;
        }

        $this->bump("round:{$roundId}");
    }

    public function flushAll(): void
    {
        if ($this->tagged()) {
            Cache::tags(['edqa'])->flush();

            return;
        }

        $this->bump('all');
    }

    private function tagged(): bool
    {
        return $this->store()->getStore() instanceof TaggableStore;
    }

    private function store(): Repository
    {
        return Cache::store();
    }

    private function generation(string $scope): int
    {
        $value = $this->store()->get(self::PREFIX."gen:{$scope}", 0);

        return is_int($value) ? $value : (int) (is_numeric($value) ? $value : 0);
    }

    private function bump(string $scope): void
    {
        $this->store()->forever(self::PREFIX."gen:{$scope}", $this->generation($scope) + 1);
    }
}
