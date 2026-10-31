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
    'pull' => [
        'name_one' => 'Name a source (:sources) or pass --all, not both.',
        'cross_source' => ':count submission(s) were already stored from another source: counted as cross-source duplicates, not stored again.',
    ],
    'reconcile' => [
        'done' => 'Reconciled the open round: :fetched read from the source, :parsed new or edited processed (:accepted accepted, :flagged flagged, :quarantined quarantined), :deleted newly flagged as deleted at source.',
        'no_round' => 'No round is open: nothing to reconcile.',
        'busy' => 'A pull of this source is running and did not finish in time; try again shortly.',
        'failed' => 'Reconcile failed: :error. Nothing was flagged; run it again.',
    ],
    // POST /webhooks/{source} (SECURITY.md §6): the only words a sender gets back.
    'webhook' => [
        'queued' => 'A pull is queued.',
        'signature_missing' => 'The request is not signed.',
        'signature_invalid' => 'The signature does not match.',
        'timestamp_stale' => 'The request is too old, or its clock too far off.',
        'token_missing' => 'The request carries no webhook token.',
        'token_invalid' => 'The webhook token does not match.',
    ],
    // The sources API (ARCHITECTURE.md §8): 409 { code, message }.
    'api' => [
        'source_not_configured' => ':source is not configured, so it cannot pull. Its settings are listed on the Data sources page.',
        'source_disabled' => ':source is switched off, so it does not pull.',
        'no_source_enabled' => 'No source is configured and switched on, so there is nothing to pull.',
    ],
    'mock' => [
        'local_only' => 'The source mocks are local only: they run with APP_ENV=local, never on a server.',
        'edits_applied' => 'The staged edits are now visible on the :source mock.',
        'overlap' => 'Of them, :count visit(s) also on :other (:copies copied with the same instance ID, :reentered entered again with a new one).',
        'no_overlap' => 'The :other mock has no visits to overlap: generate it first.',
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
