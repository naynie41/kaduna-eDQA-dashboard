<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 2C step 7 (ARCHITECTURE.md §4, §9.2.4): an assessment records its source, so Reporting
 * can count assessments by source without reading submissions (CLAUDE.md hard rule 6).
 *
 * - assessments.source_id, backfilled from each assessment's submission, then NOT NULL;
 * - a BEFORE INSERT / UPDATE OF submission_id trigger copies it from the submission, so no writer
 *   can set it and nothing that inserts an assessment has to know it exists;
 * - (submission_id, source_id) references submissions (id, source_id), ON UPDATE CASCADE, so the
 *   two can never disagree;
 * - an index on (round_id, source_id) for the count per source.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE submissions ADD CONSTRAINT submissions_id_source_id_unique UNIQUE (id, source_id)');
        DB::statement('ALTER TABLE assessments ADD COLUMN source_id bigint NULL');
        DB::statement('UPDATE assessments a SET source_id = s.source_id FROM submissions s WHERE s.id = a.submission_id');
        DB::statement('ALTER TABLE assessments ALTER COLUMN source_id SET NOT NULL');
        DB::statement(<<<'SQL'
            ALTER TABLE assessments ADD CONSTRAINT assessments_submission_source_foreign
                FOREIGN KEY (submission_id, source_id) REFERENCES submissions (id, source_id) ON UPDATE CASCADE
            SQL);
        DB::statement('CREATE INDEX assessments_round_id_source_id_index ON assessments (round_id, source_id)');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION assessments_take_source() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                NEW.source_id := (SELECT source_id FROM submissions WHERE id = NEW.submission_id);
                RETURN NEW;
            END
            $$
            SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER assessments_take_source BEFORE INSERT OR UPDATE OF submission_id, source_id ON assessments
                FOR EACH ROW EXECUTE FUNCTION assessments_take_source()
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS assessments_take_source ON assessments');
        DB::statement('DROP FUNCTION IF EXISTS assessments_take_source()');
        DB::statement('DROP INDEX IF EXISTS assessments_round_id_source_id_index');
        DB::statement('ALTER TABLE assessments DROP CONSTRAINT IF EXISTS assessments_submission_source_foreign');
        DB::statement('ALTER TABLE assessments DROP COLUMN IF EXISTS source_id');
        DB::statement('ALTER TABLE submissions DROP CONSTRAINT IF EXISTS submissions_id_source_id_unique');
    }
};
