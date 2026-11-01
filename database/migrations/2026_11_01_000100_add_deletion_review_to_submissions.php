<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * D-62 / Phase 5 step 0: a deletion at source (a submission Reconcile no longer finds at its
 * source) stays in the portal and is reviewed by an administrator, with a reason. Who and when are
 * kept on the submission; a review exists only for a deletion, and always names both.
 * Unreviewed deletions count towards the Data issues badge.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE submissions ADD COLUMN deletion_reviewed_at timestamptz NULL, ADD COLUMN deletion_reviewed_by bigint NULL REFERENCES users (id)');
        DB::statement('ALTER TABLE submissions ADD CONSTRAINT submissions_deletion_review_valid CHECK (
            (deletion_reviewed_at IS NULL AND deletion_reviewed_by IS NULL)
            OR (deleted_at_source IS NOT NULL AND deletion_reviewed_at IS NOT NULL AND deletion_reviewed_by IS NOT NULL)
        )');
        // The badge counts deletions not yet reviewed.
        DB::statement('CREATE INDEX submissions_unreviewed_deletions_idx ON submissions (id) WHERE deleted_at_source IS NOT NULL AND deletion_reviewed_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS submissions_unreviewed_deletions_idx');
        DB::statement('ALTER TABLE submissions DROP CONSTRAINT IF EXISTS submissions_deletion_review_valid');
        DB::statement('ALTER TABLE submissions DROP COLUMN IF EXISTS deletion_reviewed_by, DROP COLUMN IF EXISTS deletion_reviewed_at');
    }
};
