<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * round_aggregates v4 (database/sql/round_aggregates_v4.sql): adds each dimension per month
 * slot ({dimension}_m{slot}) for the Visuals month charts (D-46). Recreates the
 * view, its unique index and refresh_round_aggregates(). Dropping the view drops its grants:
 * edqa:db:apply-grants (run by the migrate role after migrations) restores them. down()
 * restores v3.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->recreate('round_aggregates_v4.sql');
    }

    public function down(): void
    {
        $this->recreate('round_aggregates_v3.sql');
    }

    private function recreate(string $file): void
    {
        DB::statement('DROP MATERIALIZED VIEW IF EXISTS round_aggregates');
        DB::statement((string) file_get_contents(database_path("sql/{$file}")));
        DB::statement('CREATE UNIQUE INDEX round_aggregates_key ON round_aggregates (round_id, scope_type, scope_id, owner_type, level)');

        // Unchanged, recreated with the view so the pair always matches. CONCURRENTLY cannot
        // refresh a view never populated (created WITH NO DATA), so the first refresh is plain.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION refresh_round_aggregates() RETURNS void
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
            BEGIN
                IF (SELECT ispopulated FROM pg_matviews
                    WHERE schemaname = 'public' AND matviewname = 'round_aggregates') THEN
                    REFRESH MATERIALIZED VIEW CONCURRENTLY round_aggregates;
                ELSE
                    REFRESH MATERIALIZED VIEW round_aggregates;
                END IF;
            END
            $$
            SQL);
        DB::statement('REVOKE ALL ON FUNCTION refresh_round_aggregates() FROM PUBLIC');
    }
};
