<?php

declare(strict_types=1);

namespace App\Domain\Plan\Enums;

use App\Support\Enums\HasLabel;

enum PlanActionStatus: string
{
    use HasLabel;

    case NotStarted = 'not_started';
    case InProgress = 'in_progress';
    case Done = 'done';

    protected static function labelGroup(): string
    {
        return 'plan_action_status';
    }
}
