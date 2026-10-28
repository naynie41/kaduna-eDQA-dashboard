<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Enums;

/** What kind of field-collection server a source is (submission_sources.type, ARCHITECTURE.md §5.0). */
enum SourceType: string
{
    case OdkCentral = 'odk_central';
    case Kobo = 'kobo';
}
