<?php

declare(strict_types=1);

use App\Domain\Facility\Enums\FacilityLevel;
use App\Domain\Facility\Enums\Ownership;
use App\Domain\Reporting\DTOs\ReportFilter;
use Illuminate\Validation\ValidationException;
use Tests\Support\Rows;

beforeEach(function (): void {
    Rows::lga(['code' => 'CHK', 'name' => 'Chikun']);
});

it('reads the five dashboard filters from the query string', function (): void {
    $filter = ReportFilter::fromArray(['year' => '2026', 'quarter' => '2', 'lga' => 'CHK', 'level' => 'primary', 'owner' => 'private']);

    expect($filter->year)->toBe(2026)
        ->and($filter->quarter)->toBe(2)
        ->and($filter->lga)->toBe('CHK')
        ->and($filter->level)->toBe(FacilityLevel::Primary)
        ->and($filter->owner)->toBe(Ownership::Private);
});

it('treats every filter as optional', function (): void {
    $filter = ReportFilter::fromArray([]);

    expect($filter->year)->toBeNull()->and($filter->lga)->toBeNull()->and($filter->toQuery())->toBe([]);
});

it('rejects values the dashboard cannot have', function (array $query, string $field): void {
    try {
        ReportFilter::fromArray($query);
        test()->fail('Expected a validation error');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey($field);
    }
})->with([
    'quarter 5' => [['year' => 2026, 'quarter' => 5], 'quarter'],
    'quarter without a year' => [['quarter' => 2], 'year'],
    'year as text' => [['year' => 'twenty'], 'year'],
    'LGA not one of ours' => [['lga' => 'XYZ'], 'lga'],
    'unknown level' => [['level' => 'quaternary'], 'level'],
    'unknown owner' => [['owner' => 'faith'], 'owner'],
]);

it('ignores query parameters that are not filters', function (): void {
    expect(ReportFilter::fromArray(['year' => 2026, 'page' => 3, 'sort' => 'lga'])->toQuery())->toBe(['year' => 2026]);
});

it('gives the same cache key for the same filters in any order, and different keys otherwise', function (): void {
    $a = ReportFilter::fromArray(['owner' => 'public', 'year' => '2026', 'lga' => 'CHK']);
    $b = ReportFilter::fromArray(['lga' => 'CHK', 'year' => 2026, 'owner' => 'public']);
    $c = ReportFilter::fromArray(['lga' => 'CHK', 'year' => 2026, 'owner' => 'private']);

    expect($a->cacheKey())->toBe($b->cacheKey())
        ->and($a->cacheKey())->not->toBe($c->cacheKey());
});

it('turns back into the query string for links and exports', function (): void {
    $query = ['year' => 2026, 'quarter' => 2, 'lga' => 'CHK', 'level' => 'secondary', 'owner' => 'public'];

    expect(ReportFilter::fromArray($query)->toQuery())->toBe($query)
        ->and(ReportFilter::fromArray(ReportFilter::fromArray($query)->toQuery()))->toEqual(ReportFilter::fromArray($query));
});
