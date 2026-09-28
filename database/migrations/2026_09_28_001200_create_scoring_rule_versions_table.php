<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Versioned scoring config (bands, item weights, N/A policy). Draft until published_at; the
// current version is the highest published one (ARCHITECTURE.md §7, Rule versions).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scoring_rule_versions', function (Blueprint $table): void {
            $table->id();
            $table->integer('version')->unique();
            $table->jsonb('config');
            $table->foreignId('created_by')->constrained('users');
            $table->timestampTz('published_at')->nullable();
            $table->text('published_reason')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scoring_rule_versions');
    }
};
