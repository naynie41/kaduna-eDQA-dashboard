<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Enums;

/** How an ODK pull run ended (odk_pull_runs.outcome); null while it runs. */
enum PullOutcome: string
{
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case SkippedNotConfigured = 'skipped_not_configured';
}
