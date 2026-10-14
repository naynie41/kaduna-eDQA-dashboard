<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Replaces round_aggregates with v2 (database/sql/round_aggregates_v2.sql): each round is read
 * under its own rule version (D-32). Without it, publishing a new version would drop every
 * closed round, whose scores stay under its pinned version, out of the aggregates.
 *
 * refresh_round_aggregates() names the view at run time, so it needs no change. Dropping the
 * view drops its grants: edqa:db:apply-grants (run by the migrate role after migrations)
 * restores them.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->recreate('round_aggregates_v2.sql');
    }

    public function down(): void
    {
        $this->recreate('round_aggregates.sql');
    }

    private function recreate(string $file): void
    {
        DB::statement('DROP MATERIALIZED VIEW IF EXISTS round_aggregates');
        DB::statement((string) file_get_contents(database_path("sql/{$file}")));
        DB::statement('CREATE UNIQUE INDEX round_aggregates_key ON round_aggregates (round_id, scope_type, scope_id, owner_type, level)');
    }
};
