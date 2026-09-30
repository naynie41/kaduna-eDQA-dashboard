<?php

declare(strict_types=1);

namespace App\Support\Audit;

use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Audit-trail logging for a model listed in SECURITY.md §4: only the named attributes, only
 * when they change, no entry for a save that changes nothing (CONVENTION.md §2.4), and the
 * request's IP and user agent with every entry.
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

    /**
     * Adds properties.ip and properties.user_agent (SECURITY.md §4). A change made from an
     * artisan command has no real request, so it is marked source = console instead of being
     * stamped with a meaningless 127.0.0.1.
     */
    public function tapActivity(Activity $activity, string $eventName): void
    {
        $request = request();

        $context = app()->runningInConsole() && $request->userAgent() === null
            ? ['source' => 'console']
            : ['ip' => $request->ip(), 'user_agent' => $request->userAgent()];

        $activity->properties = ($activity->properties ?? collect())->merge($context);
    }
}
