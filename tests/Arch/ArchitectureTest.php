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

// CLAUDE.md hard rule 5: no aggregate outside an Aggregator. Any AVG/SUM/COUNT in SQL (string
// literals and heredocs: PHP's own count() and array_sum() are code, not SQL) or a query
// builder ->avg()/->sum() call outside App\Domain\Reporting fails here.
it('keeps AVG, SUM and COUNT in SQL inside App\Domain\Reporting', function (): void {
    // QuarantineQuery counts Data issues records per rule: an operational badge, never a score,
    // and Reporting may not read quarantine at all (hard rule 6), so it cannot move there.
    $allowed = ['Domain/Validation/Queries/QuarantineQuery.php'];
    $root = realpath(__DIR__.'/../../app');
    $offenders = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator((string) $root, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        $relative = str_replace('\\', '/', substr((string) $file, strlen((string) $root) + 1));
        if (! str_ends_with($relative, '.php') || str_starts_with($relative, 'Domain/Reporting/') || in_array($relative, $allowed, true)) {
            continue;
        }

        $tokens = token_get_all((string) file_get_contents((string) $file));
        foreach ($tokens as $i => $token) {
            if (! is_array($token)) {
                continue;
            }
            $isString = in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true);
            if ($isString && preg_match('/\b(avg|sum|count)\s*\(/i', $token[1]) === 1) {
                $offenders[] = "{$relative}:{$token[2]} SQL aggregate in a string";
            }
            $isBuilderAggregate = $token[0] === T_STRING && in_array(strtolower($token[1]), ['avg', 'sum', 'average'], true)
                && is_array($tokens[$i - 1] ?? null) && $tokens[$i - 1][0] === T_OBJECT_OPERATOR;
            if ($isBuilderAggregate) {
                $offenders[] = "{$relative}:{$token[2]} ->{$token[1]}()";
            }
        }
    }

    expect($offenders)->toBe([]);
});

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
