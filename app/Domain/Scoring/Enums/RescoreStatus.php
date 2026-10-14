<?php

declare(strict_types=1);

namespace App\Domain\Scoring\Enums;

/** A rescore run: running until its batch finishes; failed while any chunk has failed and not been retried. */
enum RescoreStatus: string
{
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
