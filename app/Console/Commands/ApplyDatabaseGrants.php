<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Runs database/sql/post-migrate-grants.sql. The `migrate` container role calls this after
 * `migrate --force`, connected as the migrator (DEPLOY.md §6.3, §8.3).
 */
final class ApplyDatabaseGrants extends Command
{
    protected $signature = 'edqa:db:apply-grants';

    protected $description = 'Apply the least-privilege grants for the app database role';

    public function handle(): int
    {
        $path = database_path('sql/post-migrate-grants.sql');
        $sql = is_file($path) ? file_get_contents($path) : false;

        if ($sql === false) {
            $this->error("Cannot read {$path}");

            return self::FAILURE;
        }

        DB::unprepared($sql);
        $this->info('Grants applied.');

        return self::SUCCESS;
    }
}
