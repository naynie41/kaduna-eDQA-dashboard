<?php

declare(strict_types=1);

// ODK Central connection (ARCHITECTURE.md §5): error messages for administrators (never a
// password, token or payload) and edqa:odk:check.

return [

    'errors' => [
        'not_configured' => 'ODK Central is not configured. Set in .env: :problems.',
        'auth_credentials' => 'ODK Central refused the service account (HTTP :status): check ODK_CENTRAL_EMAIL and ODK_CENTRAL_PASSWORD.',
        'auth_forbidden' => 'The service account cannot read this project or form (HTTP :status): it needs the Project Viewer role on ODK_PROJECT_ID.',
        'unreachable_connection' => 'ODK Central could not be reached after :attempts attempts (network, DNS or TLS).',
        'unreachable_server' => 'ODK Central kept answering HTTP :status after :attempts attempts.',
        'unexpected_status' => 'ODK Central answered HTTP :status for :what: check ODK_PROJECT_ID and ODK_FORM_ID.',
        'unexpected_body' => 'ODK Central sent an answer the portal cannot read (HTTP :status, :what).',
    ],

    'check' => [
        'title' => 'ODK Central',
        'server' => 'Server: :url, project :project, form :form',
        'not_configured' => 'Not configured. Set in .env: :problems',
        'ok' => 'Connected as :account. Project ":project", form ":form", current version :version.',
        'failed' => 'Failed (:status): :message',
        'versions' => 'Published form versions (:count):',
        'version_mapped' => '  :version  published :published  field map: yes',
        'version_unmapped' => '  :version  published :published  field map: NO, its submissions will be quarantined (UNKNOWN_FORM_VERSION)',
        'unknown_date' => 'unknown',
        'none' => 'none',
        'unmapped_summary' => ':count published version(s) have no field map: add them to config/edqa_field_maps.php before the first pull.',
    ],

    // edqa:pull (ARCHITECTURE.md §5.3).
    'pull' => [
        'dry_run_title' => 'Dry run: nothing was written (no submission, cursor or run row).',
        'dry_run_summary' => 'Fetched :fetched; :stored already stored; of the rest :accepted would be accepted, :flagged flagged and :quarantined quarantined.',
        'dry_run_no_rules' => 'No rule was hit.',
        'rule' => 'Rule',
        'severity' => 'Severity',
        'submissions' => 'Submissions',
        'busy' => 'Another pull is running and did not finish in time; try again shortly.',
        'result' => 'Pull :outcome: fetched :fetched, :duplicates already stored, :accepted accepted, :flagged flagged, :quarantined quarantined.',
        'skipped' => 'Skipped: :error',
        'failed' => 'Pull failed: :error',
    ],

    // edqa:attachments:fetch-pending.
    'attachments' => [
        'queued' => 'Queued attachment fetches for :count submission(s) on the low queue.',
        'fetched' => 'Fetched attachments for :count submission(s); for :failed of them ODK could not deliver every file, now marked failed.',
    ],

    // edqa:odk-mock:generate (docker/odk-mock).
    'mock' => [
        'local_only' => 'The mock ODK Central is for local development only.',
        'written' => 'Wrote :valid valid, :flagged to be flagged and :broken to be quarantined, plus :edited edits (staged) and :attachments photo(s), to :path.',
        'edits_applied' => 'Edits applied: the next pull sees the edited versions.',
        'bad_round' => 'Give the round as 2026Q2, not ":round".',
        'no_round' => 'There is no round :round.',
        'bad_span' => 'Give the quarters as 2024Q1-2025Q2, oldest first, not ":span".',
    ],

];
