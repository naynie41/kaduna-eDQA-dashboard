<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * parsed: the ParsedSubmission snapshot at quarantine time (null for records created before
 * this column). corrections: an administrator's overlay of mapped fields, applied on
 * reprocessing, so submissions.payload is never mutated (CLAUDE.md hard rule 8).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quarantined_records', function (Blueprint $table): void {
            $table->jsonb('parsed')->nullable();
            $table->jsonb('corrections')->default(DB::raw("'{}'::jsonb"));
        });
    }

    public function down(): void
    {
        Schema::table('quarantined_records', function (Blueprint $table): void {
            $table->dropColumn(['parsed', 'corrections']);
        });
    }
};
