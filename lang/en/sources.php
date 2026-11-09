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
    'pull' => [
        'name_one' => 'Name a source (:sources) or pass --all, not both.',
        'cross_source' => ':count submission(s) were already stored from another source: counted as cross-source duplicates, not stored again.',
        'out_of_scope' => ':count submission(s) were not facility assessments (community visits or DDU at LGA level): stored, not scored.',
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
        'written' => 'Wrote :valid valid, :flagged to be flagged and :broken to be quarantined, plus :edited edits (staged) and :attachments photo(s), to :path.',
        'bad_round' => 'Give the round as 2026Q2, not ":round".',
        'no_round' => 'There is no round :round.',
        'bad_span' => 'Give the quarters as 2024Q1-2025Q2, oldest first, not ":span".',
        'quarters_odk_only' => '--quarters is for the ODK Central mock only.',
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
    ],

    // edqa:facilities:source-refs (D-72).
    'refs' => [
        'no_file' => 'No such file: :file',
        'header' => 'The first line must be the header ref,facility_code.',
        'blank' => 'Line :line: a ref and a facility code are both needed.',
        'twice' => 'Line :line: :ref is in the file more than once.',
        'unknown_code' => ':ref: no facility on the master list has the code :code.',
        'refused' => 'Nothing was loaded: fix the file and run it again.',
        'checked' => 'The file is good: :count reference(s) for :source. Nothing was loaded (--dry-run).',
        'loaded' => ':count references loaded for :source; the ones it had before were replaced.',
    ],

    // Reviewing a deletion at source (D-62).
    'deletion_review' => [
        'errors' => [
            'not_deleted' => 'This submission is still at its source: there is no deletion to review.',
            'already_reviewed' => 'This deletion has already been reviewed.',
        ],
    ],

    // Withdrawing and restoring an assessment deleted at source (D-62).
    'withdrawal' => [
        'errors' => [
            'round_closed' => 'Round :round is closed, so its figures are fixed. Reopen the round on the Rounds page (with a reason) first.',
            'already_withdrawn' => 'This assessment has already been withdrawn.',
            'not_deleted_at_source' => 'Its submission is still at its source: only an assessment deleted at source can be withdrawn here.',
            'not_withdrawn' => 'This assessment is not withdrawn.',
            'round_taken' => 'The facility has been assessed again in :round since, and only one assessment can count: this one cannot be restored.',
        ],
    ],
];
