<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * D-74: a submission that is not a facility assessment (a community visit, DDU at LGA level) is
 * stored with the status out_of_scope: never validated, scored or quarantined. Each pull run counts
 * them apart (pull_runs.out_of_scope).
 */
return new class extends Migration
{
    private const STATUSES = ['received', 'parsed', 'validated', 'accepted', 'flagged', 'quarantined', 'rejected', 'superseded'];

    public function up(): void
    {
        $this->statuses([...self::STATUSES, 'out_of_scope']);
        DB::statement('ALTER TABLE pull_runs ADD COLUMN out_of_scope integer NOT NULL DEFAULT 0');
        DB::statement('ALTER TABLE pull_runs ADD CONSTRAINT pull_runs_out_of_scope_nonnegative CHECK (out_of_scope >= 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE pull_runs DROP CONSTRAINT IF EXISTS pull_runs_out_of_scope_nonnegative');
        DB::statement('ALTER TABLE pull_runs DROP COLUMN IF EXISTS out_of_scope');
        // A row stored as out_of_scope has no earlier status to go back to: it is kept as received,
        // so the CHECK holds and a later pull or reprocess can read it again.
        DB::statement("UPDATE submissions SET status = 'received' WHERE status = 'out_of_scope'");
        $this->statuses(self::STATUSES);
    }

    /** @param  list<string>  $statuses */
    private function statuses(array $statuses): void
    {
        DB::statement('ALTER TABLE submissions DROP CONSTRAINT IF EXISTS submissions_status_valid');
        DB::statement('ALTER TABLE submissions ADD CONSTRAINT submissions_status_valid CHECK (status IN ('.implode(', ', array_map(fn (string $s): string => "'{$s}'", $statuses)).'))');
    }
};
