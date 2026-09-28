<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Exactly 23 rows, seeded; no runtime insert path (CLAUDE.md hard rule 9). The app role loses
// INSERT/UPDATE/DELETE on this table in post-migrate-grants.sql (DEPLOY.md §8.3).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lgas', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('code')->unique();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lgas');
    }
};
