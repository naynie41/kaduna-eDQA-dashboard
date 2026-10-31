<?php

declare(strict_types=1);

use App\Domain\Ingestion\Enums\PullTrigger;
use App\Domain\Ingestion\Jobs\PullSubmissionsJob;
use App\Domain\Ingestion\Jobs\ReconcileSourceJob;
use App\Domain\Ingestion\SourceRegistry;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Per source (ARCHITECTURE.md §5.3): a pull every ten minutes and the reconcile sweep nightly, for
// each configured source only (until its credentials are set there is nothing to pull and no run
// rows pile up). Each event has its own name, so withoutOverlapping() holds per source: ODK and Kobo
// never wait for each other. Whether a source is enabled (its env flag and the administrator's
// switch, which lives in the database) is checked when the event is due, not here, so the schedule
// can be read without a database.
$sources = app(SourceRegistry::class);
foreach ($sources->configured() as $source) {
    $enabled = fn (): bool => in_array($source, app(SourceRegistry::class)->enabled(), true);

    Schedule::job(new PullSubmissionsJob($source, PullTrigger::Scheduled))
        ->name("edqa:pull:{$source}")
        ->everyTenMinutes()
        ->withoutOverlapping()
        ->when($enabled);

    Schedule::job(new ReconcileSourceJob($source))
        ->name("edqa:reconcile:{$source}")
        ->dailyAt((string) config('edqa.pull.reconcile_at', '02:30'))
        ->withoutOverlapping()
        ->when($enabled);
}
