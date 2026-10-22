<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The ODK pull page's progress (fetched → parsed → accepted → quarantined, ARCHITECTURE.md §9.3,
// D-56): how many rows a run stored and parsed, counted as each page commits, not worked out
// in the browser or a resource.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('odk_pull_runs', function (Blueprint $table): void {
            $table->integer('parsed')->default(0);
        });
        DB::statement('ALTER TABLE odk_pull_runs ADD CONSTRAINT odk_pull_runs_parsed_nonnegative CHECK (parsed >= 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE odk_pull_runs DROP CONSTRAINT IF EXISTS odk_pull_runs_parsed_nonnegative');
        Schema::table('odk_pull_runs', function (Blueprint $table): void {
            $table->dropColumn('parsed');
        });
    }
};
