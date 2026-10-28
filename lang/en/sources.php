<?php

declare(strict_types=1);

// Field-collection sources (ARCHITECTURE.md §5.0, Phase 2C).

return [
    'errors' => [
        'unknown_source' => ':key is not a configured source. The sources are set in config/edqa.php (sources).',
        'not_registered' => 'Source :key is configured but not registered yet. Run php artisan edqa:sources:sync.',
        'no_adapter' => 'Source :key is a :type source, and the portal cannot pull from that kind yet.',
    ],
    'sync' => [
        'created' => 'Registered source :key (:name).',
        'updated' => 'Updated source :key: :changes.',
        'unchanged' => 'Source :key is up to date.',
        'orphan' => 'Source :key is registered but no longer configured. It is kept (its submissions refer to it) and stays as it is.',
    ],
    'relocate' => [
        'done' => 'Attachments moved to their source directory: :moved; already there: :already.',
        'missing' => ':count attachment(s) have no file on either disk (ids :ids); their rows are unchanged.',
    ],
];
