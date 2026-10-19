<?php

declare(strict_types=1);

use App\Domain\Ingestion\Enums\PullTrigger;
use App\Domain\Ingestion\Jobs\PullSubmissionsJob;
use App\Domain\Ingestion\Odk\OdkConfig;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ODK pull every ten minutes (ARCHITECTURE.md §5.3), only once ODK Central is configured: until
// then there is nothing to pull and no run rows pile up.
if (OdkConfig::fromConfig()->isConfigured()) {
    Schedule::job(new PullSubmissionsJob(PullTrigger::Scheduled))->everyTenMinutes()->withoutOverlapping();
}
