<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// One ODK record, stored verbatim; the payload is never mutated (CLAUDE.md hard rule 8).
// instance_id (meta/instanceID) is the idempotency key for the pull (ARCHITECTURE.md §5.3).
// status mirrors App\Domain\Ingestion\Enums\SubmissionStatus.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('submissions', function (Blueprint $table): void {
            $table->id();
            $table->string('instance_id')->unique();
            $table->string('deprecated_id')->nullable()->index();
            $table->string('form_id');
            $table->string('form_version');
            $table->jsonb('payload');
            $table->timestampTz('submitted_at');
            $table->timestampTz('received_at');
            $table->timestampTz('processed_at')->nullable();
            $table->string('status');
            $table->foreignId('superseded_by_id')->nullable()->constrained('submissions');
        });

        DB::statement("ALTER TABLE submissions ADD CONSTRAINT submissions_status_valid CHECK (status IN ('received', 'parsed', 'validated', 'accepted', 'flagged', 'quarantined', 'rejected', 'superseded'))");
        DB::statement('CREATE INDEX submissions_payload_gin ON submissions USING gin (payload jsonb_path_ops)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS submissions_payload_gin');
        DB::statement('ALTER TABLE submissions DROP CONSTRAINT IF EXISTS submissions_status_valid');
        Schema::dropIfExists('submissions');
    }
};
