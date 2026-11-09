<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SECURITY.md §2, the controls that stand in for MFA (D-84):
 * - users.failed_login_count / locked_until / locked_out_at: the progressive lockout (locked_out_at
 *   = locked until `edqa:admin:unlock`);
 * - password_histories: the last 5 password hashes, so none is reused;
 * - user_devices: a keyed hash of each IP + browser an administrator signed in from, and when it
 *   was last seen, for new-device alerts. Never the IP or user agent themselves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->integer('failed_login_count')->default(0);
            $table->timestampTz('locked_until')->nullable();
            $table->timestampTz('locked_out_at')->nullable();
        });
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_failed_login_count_non_negative CHECK (failed_login_count >= 0)');

        Schema::create('password_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('password_hash');
            $table->timestampTz('created_at');
            $table->index(['user_id', 'id']);
        });

        Schema::create('user_devices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('fingerprint', 64);
            $table->timestampTz('first_seen_at');
            $table->timestampTz('last_seen_at');
            $table->unique(['user_id', 'fingerprint']);
        });
        DB::statement("ALTER TABLE user_devices ADD CONSTRAINT user_devices_fingerprint_hex CHECK (fingerprint ~ '^[0-9a-f]{64}$')");
    }

    public function down(): void
    {
        Schema::dropIfExists('user_devices');
        Schema::dropIfExists('password_histories');
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_failed_login_count_non_negative');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['failed_login_count', 'locked_until', 'locked_out_at']);
        });
    }
};
