<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// spatie/laravel-activitylog's three published migrations (create, add event, add batch_uuid)
// folded into one, with properties as jsonb and timestamps as timestamptz (ARCHITECTURE.md §4).
// The table name is fixed: post-migrate-grants.sql revokes UPDATE/DELETE on it by name, making
// the audit trail append-only for the app role (SECURITY.md §4).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_log', function (Blueprint $table): void {
            $table->id();
            $table->string('log_name')->nullable()->index();
            $table->text('description');
            $table->nullableMorphs('subject', 'subject');
            $table->string('event')->nullable();
            $table->nullableMorphs('causer', 'causer');
            $table->jsonb('properties')->nullable();
            $table->uuid('batch_uuid')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_log');
    }
};
