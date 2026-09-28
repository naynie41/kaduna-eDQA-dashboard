<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

// ARCHITECTURE.md §4: all timestamps are timestamptz. Guards every future migration too.
it('stores every timestamp with a time zone', function (): void {
    $naive = DB::table('information_schema.columns')
        ->where('table_schema', 'public')
        ->where('data_type', 'timestamp without time zone')
        ->get(['table_name', 'column_name'])
        ->map(fn (object $c): string => "{$c->table_name}.{$c->column_name}")
        ->all();

    expect($naive)->toBe([]);
});
