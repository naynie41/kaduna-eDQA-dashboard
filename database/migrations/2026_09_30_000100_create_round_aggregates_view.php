<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// The round_aggregates materialised view (database/sql/round_aggregates.sql), its unique index,
// and refresh_round_aggregates(): the app role doesn't own the view, so it refreshes through
// this SECURITY DEFINER function, owned by the migrator (ARCHITECTURE.md §4, DEPLOY.md §1.3).
// EXECUTE is granted to edqa_app by database/sql/post-migrate-grants.sql.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement((string) file_get_contents(database_path('sql/round_aggregates.sql')));
        DB::statement('CREATE UNIQUE INDEX round_aggregates_key ON round_aggregates (round_id, scope_type, scope_id, owner_type, level)');

        // CONCURRENTLY cannot refresh a view that has never been populated, and the view is
        // created WITH NO DATA, so the first refresh after a deploy is a plain one.
        // OR REPLACE: `migrate:fresh` drops tables (and this view, by cascade) but not functions.
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

        // New functions are executable by PUBLIC; a SECURITY DEFINER one must not be.
        DB::statement('REVOKE ALL ON FUNCTION refresh_round_aggregates() FROM PUBLIC');
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS refresh_round_aggregates()');
        DB::statement('DROP MATERIALIZED VIEW IF EXISTS round_aggregates');
    }
};
