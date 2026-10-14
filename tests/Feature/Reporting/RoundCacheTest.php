<?php

declare(strict_types=1);

use App\Domain\Assessment\Events\AssessmentScored;
use App\Domain\Reporting\RoundCache;
use Illuminate\Support\Facades\Cache;

// Production may use the database cache, which has no tags (DEPLOY.md §9): RoundCache gives the
// same behaviour either way.

dataset('stores', [
    'tagged (array)' => ['array'],
    'untagged (file)' => ['file'],
]);

beforeEach(function (): void {
    $this->calls = 0;
    $this->compute = function (): string {
        $this->calls++;

        return "result {$this->calls}";
    };
});

it('computes once and answers from cache after', function (string $store): void {
    config(['cache.default' => $store]);
    Cache::store($store)->flush();
    $cache = app(RoundCache::class);

    expect($cache->remember([1], 'state', $this->compute))->toBe('result 1')
        ->and($cache->remember([1], 'state', $this->compute))->toBe('result 1')
        ->and($this->calls)->toBe(1);
})->with('stores');

it('flushes one round without touching another', function (string $store): void {
    config(['cache.default' => $store]);
    Cache::store($store)->flush();
    $cache = app(RoundCache::class);
    $cache->remember([1], 'state', $this->compute);
    $cache->remember([2], 'state', $this->compute);

    $cache->flushRound(1);

    expect($cache->remember([1], 'state', $this->compute))->toBe('result 3')
        ->and($cache->remember([2], 'state', $this->compute))->toBe('result 2');
})->with('stores');

it('flushes an entry that spans several rounds when any of them changes', function (string $store): void {
    config(['cache.default' => $store]);
    Cache::store($store)->flush();
    $cache = app(RoundCache::class);
    $cache->remember([1, 2], 'trend', $this->compute);

    $cache->flushRound(2);

    expect($cache->remember([1, 2], 'trend', $this->compute))->toBe('result 2');
})->with('stores');

it('keeps keys for different filters apart', function (string $store): void {
    config(['cache.default' => $store]);
    Cache::store($store)->flush();
    $cache = app(RoundCache::class);

    $cache->remember([1], 'state:a', $this->compute);

    expect($cache->remember([1], 'state:b', $this->compute))->toBe('result 2');
})->with('stores');

it('flushes a round when one of its assessments is scored', function (): void {
    config(['cache.default' => 'array']);
    $cache = app(RoundCache::class);
    $cache->remember([7], 'state', $this->compute);

    event(new AssessmentScored(assessmentId: 1, roundId: 7, ruleVersionId: 1));

    expect($cache->remember([7], 'state', $this->compute))->toBe('result 2');
});
