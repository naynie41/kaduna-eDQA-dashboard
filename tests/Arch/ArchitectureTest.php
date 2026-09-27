<?php

declare(strict_types=1);

// CONVENTION.md §8. Rules that need domain code (reporting never reads quarantine, no
// import namespace) are added with that code.

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
