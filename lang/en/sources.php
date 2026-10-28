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
    'check' => [
        'unknown' => ':key is not a configured source. The sources are: :keys.',
        'server_odk' => 'Server: :url, project :project, form :form',
        'server_kobo' => 'Server: :url, asset :asset',
        'not_configured' => 'Not configured. Set in .env: :problems',
        'ok' => 'Connected as :account. Project ":project", form ":form", current version :version.',
        'failed' => 'Failed (:status): :message',
        'versions' => 'Published form versions (:count):',
        'version_mapped' => '  :version  published :published  field map: yes',
        'version_unmapped' => '  :version  published :published  field map: NO, its submissions will be quarantined (UNKNOWN_FORM_VERSION)',
        'unknown_date' => 'unknown',
        'none' => 'none',
        'unmapped_summary' => ':count published version(s) have no field map: add them to config/edqa_field_maps.php (sources) before the first pull.',
        'deprecated_alias' => 'edqa:odk:check is now edqa:source:check odk. This alias goes in the next release.',
    ],
];
