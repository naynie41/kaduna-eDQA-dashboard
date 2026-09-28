<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

// CLAUDE.md hard rule 4: every migration is reversible. Rolls back one migration at a time so
// a broken down() is pinned to the migration that owns it, then proves nothing was left behind
// and that the schema migrates up again.
it('rolls back every migration one step at a time, then migrates again', function (): void {
    $this->artisan('migrate:fresh', ['--no-interaction' => true])->assertSuccessful();
    $total = DB::table('migrations')->count();

    expect($total)->toBeGreaterThan(0);

    for ($remaining = $total; $remaining > 0; $remaining--) {
        $migration = DB::table('migrations')->orderByDesc('id')->value('migration');
        $this->artisan('migrate:rollback', ['--step' => 1, '--no-interaction' => true])->assertSuccessful();

        expect(DB::table('migrations')->count())->toBe($remaining - 1, "rolling back {$migration}");
    }

    $leftover = collect(DB::select(
        "select tablename from pg_tables where schemaname = 'public' and tablename <> 'migrations'",
    ))->pluck('tablename')->all();
    expect($leftover)->toBe([]);

    $this->artisan('migrate', ['--no-interaction' => true])->assertSuccessful();
    expect(DB::table('migrations')->count())->toBe($total);
});
