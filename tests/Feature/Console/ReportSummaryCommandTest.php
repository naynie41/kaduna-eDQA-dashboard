<?php

declare(strict_types=1);

use App\Domain\Reporting\Aggregators\DimensionAggregator;
use App\Domain\Reporting\Aggregators\LgaTableAggregator;
use App\Domain\Reporting\Aggregators\OwnerSplitAggregator;
use App\Domain\Reporting\DTOs\ReportFilter;
use App\Domain\Scoring\DTOs\ScoreValue;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\ReportingFixture;

beforeEach(function (): void {
    $this->f = ReportingFixture::build();
});

function summary(array $options = []): string
{
    Artisan::call('edqa:report:summary', $options);

    return Artisan::output();
}

function cell(ScoreValue $score): string
{
    return "{$score->display} {$score->bandLabel}";
}

it('prints the latest round by default: state, owners and every LGA, as the aggregators give them', function (): void {
    $output = summary();
    $filter = new ReportFilter(year: 2026, quarter: 2);
    $dimensions = app(DimensionAggregator::class)->dimensions($filter);
    $owners = app(OwnerSplitAggregator::class)->split($filter);

    expect($output)
        ->toContain('Q2 2026')
        ->toContain('open')
        ->toContain(cell($dimensions->overall))
        ->toContain(cell($dimensions->availability))
        ->toContain(cell($owners->public->overall))
        ->toContain(cell($owners->private->overall))
        ->toContain((string) __('reporting.summary.weakest', ['dimension' => $dimensions->weakest?->label() ?? '']))
        ->not->toContain('Q1 2026');

    foreach (app(LgaTableAggregator::class)->rows($filter) as $row) {
        expect($output)->toContain($row->name)->toContain(cell($row->overall));
    }
});

it('prints every round, oldest first, after the trend, with --round=all', function (): void {
    $output = summary(['--round' => 'all']);

    expect($output)->toContain(__('reporting.summary.trend'))
        ->and(strpos($output, 'Q1 2026 (closed'))->toBeLessThan(strpos($output, 'Q2 2026 (open'));
});

it('picks one round by id or by YYYYQn, and one LGA with --lga', function (): void {
    expect(summary(['--round' => (string) $this->f->q1]))->toContain('Q1 2026')->not->toContain('Q2 2026 (open');
    expect(summary(['--round' => '2026Q1']))->toContain('Q1 2026');

    $output = summary(['--lga' => 'AAA']);
    expect($output)->toContain('Alpha')->not->toContain('Bravo')->not->toContain('Charlie');
});

it('refuses an unknown round or LGA', function (): void {
    expect(Artisan::call('edqa:report:summary', ['--round' => '999999']))->toBe(1)
        ->and(Artisan::call('edqa:report:summary', ['--round' => '2031Q2']))->toBe(1)
        ->and(Artisan::call('edqa:report:summary', ['--lga' => 'ZZZ']))->toBe(1);
});
