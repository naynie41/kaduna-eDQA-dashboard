<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Attachment fetching (ARCHITECTURE.md §5.3, SECURITY.md §7, D-52). A row is recorded, pending,
// when the pull first sees its submission: the type and size are known only once the file is
// fetched, so they become nullable. reason says why a file was rejected or failed. One row per
// submission and filename (Q-18 settles the statuses).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('odk_attachments', function (Blueprint $table): void {
            $table->string('mime')->nullable()->change();
            $table->bigInteger('size')->nullable()->change();
            $table->string('reason')->nullable();
            $table->unique(['submission_id', 'filename']);
        });

        DB::statement("ALTER TABLE odk_attachments ADD CONSTRAINT odk_attachments_status_valid CHECK (status IN ('pending', 'fetched', 'rejected', 'failed'))");
        DB::statement('ALTER TABLE odk_attachments ADD CONSTRAINT odk_attachments_size_nonnegative CHECK (size IS NULL OR size >= 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE odk_attachments DROP CONSTRAINT IF EXISTS odk_attachments_size_nonnegative');
        DB::statement('ALTER TABLE odk_attachments DROP CONSTRAINT IF EXISTS odk_attachments_status_valid');
        DB::table('odk_attachments')->whereNull('mime')->update(['mime' => '']);
        DB::table('odk_attachments')->whereNull('size')->update(['size' => 0]);

        Schema::table('odk_attachments', function (Blueprint $table): void {
            $table->dropUnique(['submission_id', 'filename']);
            $table->dropColumn('reason');
            $table->string('mime')->nullable(false)->change();
            $table->bigInteger('size')->nullable(false)->change();
        });
    }
};
