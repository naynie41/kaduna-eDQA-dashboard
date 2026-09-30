<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/**
 * has_*_privilege for edqa_app, the runtime role (created by docker/postgres/init-dev.sql).
 */
function appCan(string $privilege, string $object, string $kind = 'table'): bool
{
    return (bool) DB::selectOne("select has_{$kind}_privilege('edqa_app', ?, ?) as allowed", [$object, $privilege])->allowed;
}

// Only what post-migrate-grants.sql itself does. The baseline privileges it builds on (SELECT,
// INSERT, ... on new tables) come from the database's default privileges (DEPLOY.md §8.2),
// which the per-process databases of `pest --parallel` don't have; `make grants-check` proves
// the full picture on edqa_test, acting as edqa_app.
it('applies post-migrate-grants.sql for the app role', function (): void {
    $this->artisan('edqa:db:apply-grants')->assertSuccessful();

    expect(appCan('INSERT', 'lgas'))->toBeFalse()
        ->and(appCan('UPDATE', 'lgas'))->toBeFalse()
        ->and(appCan('DELETE', 'lgas'))->toBeFalse()
        ->and(appCan('UPDATE', 'activity_log'))->toBeFalse()
        ->and(appCan('DELETE', 'activity_log'))->toBeFalse()
        ->and(appCan('TRUNCATE', 'activity_log'))->toBeFalse()
        ->and(appCan('SELECT', 'round_aggregates'))->toBeTrue()
        ->and(appCan('EXECUTE', 'refresh_round_aggregates()', 'function'))->toBeTrue();
});

it('is safe to run twice', function (): void {
    $this->artisan('edqa:db:apply-grants')->assertSuccessful();
    $this->artisan('edqa:db:apply-grants')->assertSuccessful();

    expect(appCan('INSERT', 'lgas'))->toBeFalse();
});
