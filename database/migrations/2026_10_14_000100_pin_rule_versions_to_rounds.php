<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * D-32: a closed round pins the scoring rule version it was scored with; an open round uses the
 * current published one (rule_version_id stays NULL until it closes). Only one draft version
 * may exist at a time.
 *
 * Rounds already closed are pinned to the highest published version, the only one their scores
 * can be under before this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rounds', function (Blueprint $table): void {
            $table->foreignId('rule_version_id')->nullable()->constrained('scoring_rule_versions');
        });

        DB::statement(<<<'SQL'
            UPDATE rounds SET rule_version_id = (
                SELECT id FROM scoring_rule_versions WHERE published_at IS NOT NULL ORDER BY version DESC LIMIT 1
            )
            WHERE status = 'closed'
            SQL);

        DB::statement("ALTER TABLE rounds ADD CONSTRAINT rounds_closed_has_rule_version CHECK (status <> 'closed' OR rule_version_id IS NOT NULL)");
        DB::statement('CREATE UNIQUE INDEX scoring_rule_versions_one_draft ON scoring_rule_versions ((published_at IS NULL)) WHERE published_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS scoring_rule_versions_one_draft');
        DB::statement('ALTER TABLE rounds DROP CONSTRAINT IF EXISTS rounds_closed_has_rule_version');

        Schema::table('rounds', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('rule_version_id');
        });
    }
};
