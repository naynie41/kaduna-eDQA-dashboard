<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 2C step 1 (ARCHITECTURE.md §4, §5.0): ingestion becomes source-aware, so ODK Central and
 * KoboToolbox can share one pipeline. Data-preserving and reversible:
 *
 * - submission_sources is created with the 'odk' row; every existing submission, quarantined
 *   record, cursor and pull run belongs to it, then source_id is made NOT NULL;
 * - odk_form_syncs → source_syncs (one row per source; the form it follows kept as form_ref
 *   "project/form"; last_pulled_at → cursor_at, last_edit_at → edit_cursor_at, the second
 *   cursor ODK's edits need (D-51), last_instance_id → cursor_instance_id, backfill_skip →
 *   backfill_checkpoint; last_success_at, last_reconciled_at added);
 * - odk_pull_runs → pull_runs (+ cross_source_duplicates); odk_attachments →
 *   submission_attachments (+ source_url); their constraints, keys and sequences renamed too;
 * - submissions: form_id → form_ref; external_id (from the stored payload's __id, Central's
 *   logical ID), source_updated_at, deleted_at_source; index (source_id, external_id).
 *
 * More than one ODK cursor row (the configured form changed at some point) is refused rather
 * than guessed: the cursors belong to different forms and only one can be current.
 */
return new class extends Migration
{
    /** [old table, new table, [old constraint suffix => new]] for the renamed tables. */
    private const RENAMES = [
        ['odk_form_syncs', 'source_syncs', []],
        ['odk_pull_runs', 'pull_runs', ['trigger_type_valid', 'outcome_valid', 'parsed_nonnegative', 'triggered_by_foreign']],
        ['odk_attachments', 'submission_attachments', ['status_valid', 'size_nonnegative', 'submission_id_filename_unique', 'submission_id_foreign']],
    ];

    public function up(): void
    {
        $cursors = (int) DB::scalar('SELECT count(*) FROM odk_form_syncs');
        if ($cursors > 1) {
            throw new RuntimeException("odk_form_syncs holds {$cursors} cursor rows (one per ODK form ever configured); source_syncs keeps one per source. Delete the rows for forms no longer pulled, then migrate again.");
        }

        DB::statement(<<<'SQL'
            CREATE TABLE submission_sources (
                id bigserial PRIMARY KEY,
                key varchar(32) NOT NULL,
                type varchar(32) NOT NULL,
                name varchar(255) NOT NULL,
                is_enabled boolean NOT NULL DEFAULT true,
                created_at timestamptz NULL,
                updated_at timestamptz NULL,
                CONSTRAINT submission_sources_key_unique UNIQUE (key),
                CONSTRAINT submission_sources_key_format CHECK (key ~ '^[a-z][a-z0-9_]{0,31}$'),
                CONSTRAINT submission_sources_type_valid CHECK (type IN ('odk_central', 'kobo'))
            )
            SQL);
        $odk = (int) DB::scalar("INSERT INTO submission_sources (key, type, name, is_enabled, created_at, updated_at) VALUES ('odk', 'odk_central', 'ODK Central', true, now(), now()) RETURNING id");

        foreach (self::RENAMES as [$old, $new, $constraints]) {
            $this->renameTable($old, $new, $constraints);
        }

        // The cursor: one row per source, the form it follows kept as form_ref.
        DB::statement('ALTER TABLE source_syncs DROP CONSTRAINT IF EXISTS odk_form_syncs_project_id_form_id_unique');
        DB::statement('ALTER TABLE source_syncs ADD COLUMN source_id bigint NULL REFERENCES submission_sources (id)');
        DB::statement('ALTER TABLE source_syncs ADD COLUMN form_ref varchar(255) NULL');
        DB::update("UPDATE source_syncs SET source_id = ?, form_ref = project_id || '/' || form_id", [$odk]);
        DB::statement('ALTER TABLE source_syncs DROP COLUMN project_id, DROP COLUMN form_id');
        DB::statement('ALTER TABLE source_syncs RENAME COLUMN last_pulled_at TO cursor_at');
        DB::statement('ALTER TABLE source_syncs RENAME COLUMN last_edit_at TO edit_cursor_at');
        DB::statement('ALTER TABLE source_syncs RENAME COLUMN last_instance_id TO cursor_instance_id');
        DB::statement('ALTER TABLE source_syncs RENAME COLUMN backfill_skip TO backfill_checkpoint');
        DB::statement('ALTER TABLE source_syncs ADD COLUMN last_success_at timestamptz NULL, ADD COLUMN last_reconciled_at timestamptz NULL');
        DB::statement('ALTER TABLE source_syncs ALTER COLUMN source_id SET NOT NULL');
        DB::statement('ALTER TABLE source_syncs ADD CONSTRAINT source_syncs_source_id_unique UNIQUE (source_id)');
        DB::statement('ALTER TABLE source_syncs RENAME CONSTRAINT source_syncs_source_id_fkey TO source_syncs_source_id_foreign');

        // Pull runs.
        DB::statement('ALTER TABLE pull_runs ADD COLUMN source_id bigint NULL REFERENCES submission_sources (id)');
        DB::statement('ALTER TABLE pull_runs ADD COLUMN cross_source_duplicates integer NOT NULL DEFAULT 0');
        DB::statement('ALTER TABLE pull_runs ADD CONSTRAINT pull_runs_cross_source_duplicates_nonnegative CHECK (cross_source_duplicates >= 0)');
        DB::update('UPDATE pull_runs SET source_id = ?', [$odk]);
        DB::statement('ALTER TABLE pull_runs ALTER COLUMN source_id SET NOT NULL');
        DB::statement('ALTER TABLE pull_runs RENAME CONSTRAINT pull_runs_source_id_fkey TO pull_runs_source_id_foreign');
        DB::statement('CREATE INDEX pull_runs_source_id_started_at_index ON pull_runs (source_id, started_at)');

        // Attachments: the source's own URL for the file, when it gives one (Kobo).
        DB::statement('ALTER TABLE submission_attachments ADD COLUMN source_url text NULL');

        // Submissions.
        DB::statement('ALTER TABLE submissions RENAME COLUMN form_id TO form_ref');
        DB::statement('ALTER TABLE submissions ADD COLUMN source_id bigint NULL REFERENCES submission_sources (id)');
        DB::statement('ALTER TABLE submissions ADD COLUMN external_id varchar(255) NULL, ADD COLUMN source_updated_at timestamptz NULL, ADD COLUMN deleted_at_source timestamptz NULL');
        // The raw payload is read, never written (hard rule 8).
        DB::update("UPDATE submissions SET source_id = ?, external_id = NULLIF(payload->>'__id', '')", [$odk]);
        DB::statement('ALTER TABLE submissions ALTER COLUMN source_id SET NOT NULL');
        DB::statement('ALTER TABLE submissions RENAME CONSTRAINT submissions_source_id_fkey TO submissions_source_id_foreign');
        DB::statement('CREATE INDEX submissions_source_id_external_id_index ON submissions (source_id, external_id)');

        // Quarantined records: the source of their submission.
        DB::statement('ALTER TABLE quarantined_records ADD COLUMN source_id bigint NULL REFERENCES submission_sources (id)');
        DB::update('UPDATE quarantined_records q SET source_id = s.source_id FROM submissions s WHERE s.id = q.submission_id');
        DB::statement('ALTER TABLE quarantined_records ALTER COLUMN source_id SET NOT NULL');
        DB::statement('ALTER TABLE quarantined_records RENAME CONSTRAINT quarantined_records_source_id_fkey TO quarantined_records_source_id_foreign');
    }

    public function down(): void
    {
        // The ODK-only schema cannot say where another source's rows came from: refuse, never
        // relabel them as ODK.
        foreach (['submissions', 'pull_runs', 'source_syncs'] as $table) {
            $other = (int) DB::scalar("SELECT count(*) FROM {$table} t JOIN submission_sources s ON s.id = t.source_id WHERE s.key <> 'odk'");
            if ($other > 0) {
                throw new RuntimeException("{$table} holds {$other} row(s) from a source other than odk; the ODK-only schema cannot hold them. Remove them first.");
            }
        }

        DB::statement('ALTER TABLE quarantined_records DROP COLUMN source_id');

        DB::statement('DROP INDEX submissions_source_id_external_id_index');
        DB::statement('ALTER TABLE submissions DROP COLUMN source_id, DROP COLUMN external_id, DROP COLUMN source_updated_at, DROP COLUMN deleted_at_source');
        DB::statement('ALTER TABLE submissions RENAME COLUMN form_ref TO form_id');

        DB::statement('ALTER TABLE submission_attachments DROP COLUMN source_url');

        DB::statement('DROP INDEX pull_runs_source_id_started_at_index');
        DB::statement('ALTER TABLE pull_runs DROP COLUMN source_id, DROP COLUMN cross_source_duplicates');

        DB::statement('ALTER TABLE source_syncs DROP COLUMN last_success_at, DROP COLUMN last_reconciled_at');
        DB::statement('ALTER TABLE source_syncs RENAME COLUMN cursor_at TO last_pulled_at');
        DB::statement('ALTER TABLE source_syncs RENAME COLUMN edit_cursor_at TO last_edit_at');
        DB::statement('ALTER TABLE source_syncs RENAME COLUMN cursor_instance_id TO last_instance_id');
        DB::statement('ALTER TABLE source_syncs RENAME COLUMN backfill_checkpoint TO backfill_skip');
        DB::statement('ALTER TABLE source_syncs ADD COLUMN project_id integer NULL, ADD COLUMN form_id varchar(255) NULL');
        DB::update("UPDATE source_syncs SET project_id = NULLIF(split_part(form_ref, '/', 1), '')::integer, form_id = substr(form_ref, strpos(form_ref, '/') + 1)");
        DB::statement('ALTER TABLE source_syncs ALTER COLUMN project_id SET NOT NULL, ALTER COLUMN form_id SET NOT NULL');
        DB::statement('ALTER TABLE source_syncs DROP COLUMN source_id, DROP COLUMN form_ref');
        DB::statement('ALTER TABLE source_syncs ADD CONSTRAINT odk_form_syncs_project_id_form_id_unique UNIQUE (project_id, form_id)');

        foreach (array_reverse(self::RENAMES) as [$old, $new, $constraints]) {
            $this->renameTable($new, $old, $constraints);
        }

        DB::statement('DROP TABLE submission_sources');
    }

    /**
     * Renames a table with its sequence, primary key and the named constraints, so nothing keeps
     * the old table's name (CONVENTION.md §3).
     *
     * @param  list<string>  $constraints  suffixes after "{table}_"
     */
    private function renameTable(string $from, string $to, array $constraints): void
    {
        DB::statement("ALTER TABLE {$from} RENAME TO {$to}");
        DB::statement("ALTER SEQUENCE {$from}_id_seq RENAME TO {$to}_id_seq");
        DB::statement("ALTER TABLE {$to} RENAME CONSTRAINT {$from}_pkey TO {$to}_pkey");
        foreach ($constraints as $suffix) {
            DB::statement("ALTER TABLE {$to} RENAME CONSTRAINT {$from}_{$suffix} TO {$to}_{$suffix}");
        }
    }
};
