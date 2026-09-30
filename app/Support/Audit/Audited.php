<?php

declare(strict_types=1);

namespace App\Support\Audit;

use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Audit-trail logging for a model listed in SECURITY.md §4: only the named attributes, only
 * when they change, and no entry for a save that changes nothing (CONVENTION.md §2.4).
 */
trait Audited
{
    use LogsActivity;

    /**
     * Attributes recorded in properties.old / properties.attributes. Never secrets.
     *
     * @return list<string>
     */
    abstract protected function auditedAttributes(): array;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly($this->auditedAttributes())
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
