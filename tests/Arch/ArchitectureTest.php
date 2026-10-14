<?php

declare(strict_types=1);

// CONVENTION.md §8.

arch('every class declares strict types')
    ->expect(['App', 'Database', 'Tests'])
    ->toUseStrictTypes();

arch('no permissions package: one role, no gates or policies')
    ->expect('Spatie\Permission')
    ->not->toBeUsed();

arch('controllers do not query the database directly')
    ->expect('App\Http\Controllers')
    ->not->toUse('Illuminate\Support\Facades\DB');

arch('no debugging functions')
    ->expect(['dd', 'dump', 'ray', 'var_dump'])
    ->not->toBeUsed();

// CLAUDE.md hard rule 6: no aggregate reads a table that can hold an unvalidated row.
// App\Domain\Reporting is still empty; the rule is in force before its first class exists.
arch('reporting never reads quarantine or raw submissions')
    ->expect('App\Domain\Reporting')
    ->not->toUse([
        'App\Domain\Validation\Models\QuarantinedRecord',
        'App\Domain\Ingestion\Models\Submission',
    ]);

// The same rule from the other side, so it holds before Reporting has a single class: only
// ingestion, validation, their models' relationships and the Data issues endpoints may touch
// quarantine or raw submissions. A new reader anywhere else fails here.
arch('only ingestion, validation and the Data issues endpoints touch quarantine or raw submissions')
    ->expect([
        'App\Domain\Validation\Models\QuarantinedRecord',
        'App\Domain\Ingestion\Models\Submission',
    ])
    ->toOnlyBeUsedIn([
        'App\Domain\Ingestion',
        'App\Domain\Validation',
        'App\Domain\Assessment\Models',
        'App\Domain\Round\Models',
        'App\Http\Controllers\Quarantine',
        'App\Http\Resources\Quarantine',
        'Database\Factories',
        'Tests',
    ]);

// CLAUDE.md hard rule 1: ODK is the only data source.
it('has no CSV import path', function (): void {
    // Arch tests don't boot Laravel, so no app_path() here.
    expect(__DIR__.'/../../app/Domain/Ingestion/Csv')->not->toBeDirectory();
});

// Domain models live in their domain; only User (Fortify) stays in App\Models.
arch('domain models extend Eloquent and are final')
    ->expect('App\Domain\*\Models')
    ->toExtend('Illuminate\Database\Eloquent\Model')
    ->toBeFinal();
